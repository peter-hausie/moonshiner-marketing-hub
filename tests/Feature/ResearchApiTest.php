<?php

namespace Tests\Feature;

use App\Models\AgentPrincipal;
use App\Services\Agents\ResearchJobLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ResearchApiTest extends TestCase
{
    use RefreshDatabase;

    private function input(): array
    {
        return ['attempt_number' => 1, 'job_id' => 'job-1', 'idempotency_key' => 'item-1',
            'title' => ' Observation ', 'source_url' => 'https://example.org/news?private=sample',
            'observed_at' => '2026-10-16T10:00:00+02:00'];
    }

    private function authenticate(): AgentPrincipal
    {
        config(['agents.research_api_enabled' => true]);
        $agent = AgentPrincipal::forceCreate(['name' => 'journalist', 'enabled' => true]);
        $issued = $agent->createToken('fixture', ['research:write'], now()->addHour());
        $this->withToken($issued->plainTextToken);
        $jobs = app(ResearchJobLifecycle::class);
        $job = $jobs->register($agent, 'job-1', hash('sha256', 'context'));
        $jobs->begin($agent, $job->id);

        return $agent;
    }

    public function test_disabled_route_and_missing_authentication_do_not_write(): void
    {
        $this->postJson('/api/v1/research', $this->input())->assertNotFound();
        config(['agents.research_api_enabled' => true]);
        $this->postJson('/api/v1/research', $this->input())->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');
        $this->assertDatabaseCount('research_items', 0);
    }

    public function test_only_boolean_true_enables_the_api_even_with_valid_credentials(): void
    {
        $agent = $this->authenticate();
        $tables = ['research_items', 'research_submission_receipts', 'personal_access_tokens', 'agent_jobs', 'agent_job_attempts'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        foreach ([false, null, 0, 1, '', 'false', 'true', 'off', 'on', '1', [], ['enabled']] as $flag) {
            config(['agents.research_api_enabled' => $flag]);
            Auth::forgetGuards();
            $this->postJson('/api/v1/research', $this->input())->assertNotFound()
                ->assertExactJson(['error' => 'not_found']);
            foreach ($tables as $table) {
                $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
            }
            $this->assertSame(0, RateLimiter::attempts('research-api:'.$agent->id));
        }
        config(['agents.research_api_enabled' => true]);
        Auth::forgetGuards();
        $this->postJson('/api/v1/research', $this->input())->assertOk();
    }

    public function test_real_bearer_accepts_replays_and_rejects_conflicts_and_spoofing(): void
    {
        $this->authenticate();
        $first = $this->postJson('/api/v1/research', $this->input())->assertOk()->json();
        Auth::forgetGuards();
        $this->postJson('/api/v1/research', $this->input())->assertOk()->assertExactJson($first);
        $this->postJson('/api/v1/research', array_replace($this->input(), ['title' => 'Changed']))
            ->assertConflict()->assertJsonPath('error', 'idempotency_conflict');
        $this->postJson('/api/v1/research', $this->input() + ['agent' => 'journalist'])
            ->assertUnprocessable()->assertJsonPath('error', 'invalid_submission');
        $this->assertDatabaseCount('research_items', 1);
        $this->assertDatabaseHas('research_items', ['title' => ' Observation ']);
        $this->assertDatabaseCount('research_submission_receipts', 1);
        $this->assertDatabaseCount('content_items', 0);
    }

    public function test_attempt_envelope_is_required_and_never_coerced_or_falls_back(): void
    {
        $this->authenticate();
        $missing = $this->input();
        unset($missing['attempt_number']);
        $this->postJson('/api/v1/research', $missing)->assertUnprocessable();
        foreach ([null, 0, -1, '1', true, 1.5, [], PHP_INT_MAX] as $number) {
            $this->postJson('/api/v1/research', array_replace($this->input(), ['attempt_number' => $number]))
                ->assertStatus($number === PHP_INT_MAX ? 409 : 422);
        }
        foreach (['unknown', 'Job-1'] as $job) {
            $this->postJson('/api/v1/research', array_replace($this->input(), ['job_id' => $job]))->assertConflict();
        }
        $this->assertDatabaseCount('research_items', 0);
        $this->assertDatabaseCount('research_submission_receipts', 0);
        $this->assertDatabaseCount('agent_jobs', 1);
        $this->assertDatabaseCount('agent_job_attempts', 1);
    }

    public function test_http_replay_closure_and_retry_preserve_attempt_binding(): void
    {
        $agent = $this->authenticate();
        $jobs = app(ResearchJobLifecycle::class);
        $job = DB::table('agent_jobs')->first();
        $attempt = DB::table('agent_job_attempts')->first();
        $first = $this->postJson('/api/v1/research', $this->input())->assertOk()
            ->assertJsonPath('attempt_number', 1)->json();
        $receipt = DB::table('research_submission_receipts')->first();
        $this->assertSame($attempt->id, $receipt->agent_job_attempt_id);
        $jobs->finish($agent, $job->id, 1, 'transport_timeout');
        $this->postJson('/api/v1/research', $this->input())->assertOk()->assertExactJson($first);
        $this->postJson('/api/v1/research', array_replace($this->input(), ['idempotency_key' => 'late']))->assertConflict();
        $next = $jobs->begin($agent, $job->id);
        $this->postJson('/api/v1/research', $this->input())->assertConflict();
        $this->postJson('/api/v1/research', array_replace($this->input(), ['attempt_number' => 2]))->assertConflict();
        $this->assertEquals($receipt, DB::table('research_submission_receipts')->first());
        $this->postJson('/api/v1/research', array_replace($this->input(), ['attempt_number' => 2, 'idempotency_key' => 'item-2']))
            ->assertOk()->assertJsonPath('attempt_number', 2);
        $this->assertDatabaseHas('research_submission_receipts', ['agent_job_attempt_id' => $next->id]);
        $this->assertDatabaseCount('research_items', 2);
        $this->assertDatabaseHas('agent_jobs', ['id' => $job->id, 'status' => 'running']);
    }

    public function test_malformed_non_object_oversized_and_non_json_are_rejected(): void
    {
        $this->authenticate();
        foreach (['{', '[]', 'null'] as $body) {
            $this->call('POST', '/api/v1/research', [], [], [], array_merge($this->transformHeadersToServerVars($this->defaultHeaders), ['CONTENT_TYPE' => 'application/json']), $body)->assertStatus(400);
        }
        $this->call('POST', '/api/v1/research', [], [], [], array_merge($this->transformHeadersToServerVars($this->defaultHeaders), ['CONTENT_TYPE' => 'application/json']), str_repeat(' ', 65537))->assertStatus(413);
        $this->post('/api/v1/research', $this->input())->assertStatus(415);
        $this->assertDatabaseCount('research_items', 0);
    }

    public function test_limit_is_shared_by_rotated_tokens_and_returns_retry_guidance(): void
    {
        $agent = $this->authenticate();
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit('research-api:'.$agent->id, 60);
        }
        $rotated = $agent->createToken('rotated', ['research:write'], now()->addHour());
        Auth::forgetGuards();
        $this->withToken($rotated->plainTextToken)->postJson('/api/v1/research', $this->input())
            ->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->assertDatabaseCount('research_items', 0);
        $this->travel(61)->seconds();
        Auth::forgetGuards();
        $this->postJson('/api/v1/research', $this->input())->assertOk();
    }

    public function test_storage_failure_is_redacted_and_retry_succeeds_without_partial_write(): void
    {
        $this->authenticate();
        Log::spy();
        DB::statement("CREATE TRIGGER reject_api_receipt BEFORE INSERT ON research_submission_receipts BEGIN SELECT RAISE(ABORT, 'private-storage-detail'); END");
        $response = $this->postJson('/api/v1/research', $this->input())->assertStatus(503)
            ->assertJsonPath('error', 'temporarily_unavailable')->assertHeader('Retry-After', '60');
        $this->assertStringNotContainsString('private', $response->getContent());
        Log::shouldHaveReceived('warning')->once()->with('agent_research_rejected', \Mockery::on(function ($context) {
            return array_keys($context) === ['request_id', 'status', 'code', 'principal_id']
                && $context['status'] === 503 && ! str_contains(json_encode($context), 'private');
        }));
        $this->assertDatabaseCount('research_items', 0);
        DB::statement('DROP TRIGGER reject_api_receipt');
        Auth::forgetGuards();
        $this->postJson('/api/v1/research', $this->input())->assertOk();
        $this->assertDatabaseCount('research_items', 1);
    }
}
