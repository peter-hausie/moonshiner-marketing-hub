<?php

namespace Tests\Feature;

use App\Models\AgentPrincipal;
use App\Models\ContentItem;
use App\Models\User;
use App\Services\Agents\DraftContext;
use App\Services\Agents\DraftJobLifecycle;
use App\Services\Content\CreateContentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class DraftApiTest extends TestCase
{
    use RefreshDatabase;

    private array $context = [];

    private function input(): array
    {
        return $this->context + ['attempt_number' => 1, 'job_id' => 'job-1', 'idempotency_key' => 'item-1',
            'title' => ' Proposal ', 'body' => 'private draft body'];
    }

    private function authenticate(): AgentPrincipal
    {
        config(['agents.draft_api_enabled' => true]);
        $agent = AgentPrincipal::forceCreate(['name' => 'underwood', 'enabled' => true]);
        $issued = $agent->createToken('fixture', ['draft:write'], now()->addHour());
        $this->withToken($issued->plainTextToken);
        $item = ContentItem::factory()->create();
        app(CreateContentVersion::class)->create($item->id, $item->submitted_by, 'human_snapshot');
        $context = DraftContext::forContentItem($item->id);
        $this->context = array_intersect_key($context->data, array_flip(['content_item_id', 'expected_version', 'base_sha256']));
        $jobs = app(DraftJobLifecycle::class);
        $job = $jobs->register($agent, 'job-1', $context->jobFingerprint());
        $jobs->begin($agent, $job->id);

        return $agent;
    }

    public function test_disabled_route_and_missing_authentication_do_not_write(): void
    {
        $this->postJson('/api/v1/drafts', $this->input())->assertNotFound();
        config(['agents.draft_api_enabled' => true]);
        $this->postJson('/api/v1/drafts', $this->input())->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');
        $this->assertDatabaseCount('draft_submission_receipts', 0);
    }

    public function test_only_boolean_true_enables_the_api_even_with_valid_credentials(): void
    {
        $agent = $this->authenticate();
        $tables = ['content_items', 'content_versions', 'draft_submission_receipts', 'personal_access_tokens', 'agent_jobs', 'agent_job_attempts'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        foreach ([false, null, 0, 1, '', 'false', 'true', 'off', 'on', '1', [], ['enabled']] as $flag) {
            config(['agents.draft_api_enabled' => $flag]);
            Auth::forgetGuards();
            $this->postJson('/api/v1/drafts', $this->input())->assertNotFound()
                ->assertExactJson(['error' => 'not_found']);
            foreach ($tables as $table) {
                $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
            }
            $this->assertSame(0, RateLimiter::attempts('draft-api:'.$agent->id));
        }
        config(['agents.draft_api_enabled' => true]);
        Auth::forgetGuards();
        $this->postJson('/api/v1/drafts', $this->input())->assertOk();
    }

    public function test_real_bearer_accepts_replays_and_rejects_conflicts_and_spoofing(): void
    {
        $this->authenticate();
        $first = $this->postJson('/api/v1/drafts', $this->input())->assertOk()->json();
        Auth::forgetGuards();
        $this->postJson('/api/v1/drafts', $this->input())->assertOk()->assertExactJson($first);
        $this->postJson('/api/v1/drafts', array_replace($this->input(), ['title' => 'Changed']))
            ->assertConflict()->assertJsonPath('error', 'idempotency_conflict');
        $this->postJson('/api/v1/drafts', $this->input() + ['agent' => 'underwood'])
            ->assertUnprocessable()->assertJsonPath('error', 'invalid_submission');
        $this->assertDatabaseCount('content_versions', 2);
        $this->assertDatabaseHas('content_versions', ['title' => ' Proposal ', 'created_by' => null]);
        $this->assertDatabaseCount('draft_submission_receipts', 1);
        $this->assertDatabaseCount('content_items', 1);
    }

    public function test_attempt_envelope_is_required_and_never_coerced_or_falls_back(): void
    {
        $this->authenticate();
        $missing = $this->input();
        unset($missing['attempt_number']);
        $this->postJson('/api/v1/drafts', $missing)->assertUnprocessable();
        foreach ([null, 0, -1, '1', true, 1.5, [], PHP_INT_MAX] as $number) {
            $this->postJson('/api/v1/drafts', array_replace($this->input(), ['attempt_number' => $number]))
                ->assertStatus($number === PHP_INT_MAX ? 409 : 422);
        }
        foreach (['unknown', 'Job-1'] as $job) {
            $this->postJson('/api/v1/drafts', array_replace($this->input(), ['job_id' => $job]))->assertConflict();
        }
        $this->assertDatabaseCount('draft_submission_receipts', 0);
        $this->assertDatabaseCount('draft_submission_receipts', 0);
        $this->assertDatabaseCount('agent_jobs', 1);
        $this->assertDatabaseCount('agent_job_attempts', 1);
    }

    public function test_http_replay_closure_and_retry_preserve_attempt_binding(): void
    {
        $agent = $this->authenticate();
        $jobs = app(DraftJobLifecycle::class);
        $job = DB::table('agent_jobs')->first();
        $attempt = DB::table('agent_job_attempts')->first();
        $first = $this->postJson('/api/v1/drafts', $this->input())->assertOk()
            ->assertJsonPath('attempt_number', 1)->json();
        $receipt = DB::table('draft_submission_receipts')->first();
        $this->assertSame($attempt->id, $receipt->agent_job_attempt_id);
        $jobs->finish($agent, $job->id, 1, 'transport_timeout');
        $this->postJson('/api/v1/drafts', $this->input())->assertOk()->assertExactJson($first);
        $this->postJson('/api/v1/drafts', array_replace($this->input(), ['idempotency_key' => 'late']))->assertConflict();
        $next = $jobs->begin($agent, $job->id);
        $this->postJson('/api/v1/drafts', $this->input())->assertConflict();
        $this->postJson('/api/v1/drafts', array_replace($this->input(), ['attempt_number' => 2]))->assertConflict();
        $this->assertEquals($receipt, DB::table('draft_submission_receipts')->first());
        $this->assertDatabaseCount('content_versions', 2);
        $this->assertDatabaseHas('agent_jobs', ['id' => $job->id, 'status' => 'running']);
    }

    public function test_research_credentials_cookies_and_revoked_draft_tokens_cannot_submit(): void
    {
        config(['agents.draft_api_enabled' => true, 'agents.research_api_enabled' => true]);
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/drafts', $this->input())->assertUnauthorized();
        $journalist = AgentPrincipal::forceCreate(['name' => 'journalist', 'enabled' => true]);
        $token = $journalist->createToken('fixture', ['research:write'], now()->addHour());
        Auth::forgetGuards();
        $this->withToken($token->plainTextToken)->postJson('/api/v1/drafts', $this->input())->assertForbidden();
        Auth::forgetGuards();
        $agent = $this->authenticate();
        $this->postJson('/api/v1/research', $this->input())->assertForbidden();
        Auth::forgetGuards();
        $this->postJson('/api/v1/drafts', $this->input())->assertOk();
        $agent->tokens()->delete();
        Auth::forgetGuards();
        $this->postJson('/api/v1/drafts', $this->input())->assertUnauthorized();
        $this->assertDatabaseCount('content_versions', 2);
        $this->assertDatabaseCount('draft_submission_receipts', 1);
        $this->assertDatabaseCount('research_items', 0);
    }

    public function test_registered_context_and_current_human_edit_are_enforced_over_http(): void
    {
        $this->authenticate();
        $this->postJson('/api/v1/drafts', array_replace($this->input(), ['base_sha256' => str_repeat('a', 64)]))
            ->assertConflict();
        DB::table('content_items')->where('id', $this->context['content_item_id'])->update(['body' => 'Human edit']);
        $this->postJson('/api/v1/drafts', $this->input())->assertConflict();
        $this->assertDatabaseCount('content_versions', 1);
        $this->assertDatabaseCount('draft_submission_receipts', 0);
        $this->assertDatabaseHas('content_items', ['body' => 'Human edit']);
    }

    public function test_malformed_non_object_oversized_and_non_json_are_rejected(): void
    {
        $this->authenticate();
        foreach (['{', '[]', 'null'] as $body) {
            $this->call('POST', '/api/v1/drafts', [], [], [], array_merge($this->transformHeadersToServerVars($this->defaultHeaders), ['CONTENT_TYPE' => 'application/json']), $body)->assertStatus(400);
        }
        $this->call('POST', '/api/v1/drafts', [], [], [], array_merge($this->transformHeadersToServerVars($this->defaultHeaders), ['CONTENT_TYPE' => 'application/json']), str_repeat(' ', 65537))->assertStatus(413);
        $this->post('/api/v1/drafts', $this->input())->assertStatus(415);
        $this->assertDatabaseCount('draft_submission_receipts', 0);
    }

    public function test_limit_is_shared_by_rotated_tokens_and_returns_retry_guidance(): void
    {
        $agent = $this->authenticate();
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit('draft-api:'.$agent->id, 60);
        }
        $rotated = $agent->createToken('rotated', ['draft:write'], now()->addHour());
        Auth::forgetGuards();
        $this->withToken($rotated->plainTextToken)->postJson('/api/v1/drafts', $this->input())
            ->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->assertDatabaseCount('draft_submission_receipts', 0);
        $this->travel(61)->seconds();
        Auth::forgetGuards();
        $this->postJson('/api/v1/drafts', $this->input())->assertOk();
    }

    public function test_storage_failure_is_redacted_and_retry_succeeds_without_partial_write(): void
    {
        $this->authenticate();
        Log::spy();
        DB::statement("CREATE TRIGGER reject_api_receipt BEFORE INSERT ON draft_submission_receipts BEGIN SELECT RAISE(ABORT, 'private-storage-detail'); END");
        $response = $this->postJson('/api/v1/drafts', $this->input())->assertStatus(503)
            ->assertJsonPath('error', 'temporarily_unavailable')->assertHeader('Retry-After', '60');
        $this->assertStringNotContainsString('private', $response->getContent());
        Log::shouldHaveReceived('warning')->once()->with('agent_draft_rejected', \Mockery::on(function ($context) {
            return array_keys($context) === ['request_id', 'status', 'code', 'principal_id']
                && $context['status'] === 503 && ! str_contains(json_encode($context), 'private');
        }));
        $this->assertDatabaseCount('draft_submission_receipts', 0);
        DB::statement('DROP TRIGGER reject_api_receipt');
        Auth::forgetGuards();
        $this->postJson('/api/v1/drafts', $this->input())->assertOk();
        $this->assertDatabaseCount('content_versions', 2);
    }
}
