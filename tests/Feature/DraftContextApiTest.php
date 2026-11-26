<?php

namespace Tests\Feature;

use App\Models\AgentPrincipal;
use App\Models\ContentItem;
use App\Models\User;
use App\Services\Agents\DraftContext;
use App\Services\Agents\DraftJobLifecycle;
use App\Services\Agents\ReadDraftJobContext;
use App\Services\Content\CreateContentVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class DraftContextApiTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['agents.draft_api_enabled' => true]);
        $agent = AgentPrincipal::forceCreate(['name' => 'underwood', 'enabled' => true]);
        $token = $agent->createToken('fixture', ['draft:write'], now()->addHour());
        $this->withToken($token->plainTextToken);
        $item = ContentItem::factory()->create(['body' => 'Private editorial context']);
        app(CreateContentVersion::class)->create($item->id, $item->submitted_by, 'human_snapshot');
        $context = DraftContext::forContentItem($item->id);
        $jobs = app(DraftJobLifecycle::class);
        $job = $jobs->register($agent, 'context-job', $context->jobFingerprint());
        $jobs->begin($agent, $job->id);
        $agent->withAccessToken($token->accessToken);

        return [$agent, $item, $context, $job, ['job_id' => 'context-job', 'attempt_number' => 1, 'content_item_id' => $item->id]];
    }

    private function rows(): array
    {
        $result = [];
        foreach (['content_items', 'content_versions', 'agent_jobs', 'agent_job_attempts', 'draft_submission_receipts', 'publications'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $result;
    }

    public function test_context_is_minimal_job_bound_repeatable_and_does_not_modify_editorial_or_job_rows(): void
    {
        [$agent, $item, $context, $job, $input] = $this->fixture();
        $before = $this->rows();
        for ($i = 0; $i < 2; $i++) {
            Auth::forgetGuards();
            $response = $this->postJson('/api/v1/draft-context', $input)->assertOk()
                ->assertExactJson($context->data + ['job_id' => 'context-job', 'attempt_number' => 1]);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->assertSame($before, $this->rows());
        // Returned tuple is directly usable by the existing result endpoint.
        $this->postJson('/api/v1/drafts', array_intersect_key($context->data, array_flip(['content_item_id', 'expected_version', 'base_sha256']))
            + ['job_id' => 'context-job', 'attempt_number' => 1, 'idempotency_key' => 'result', 'title' => 'Proposal', 'body' => 'Draft'])->assertOk();
    }

    public function test_other_piece_unknown_job_and_human_changes_do_not_disclose_context(): void
    {
        [$agent, $item, $context, $job, $input] = $this->fixture();
        $other = ContentItem::factory()->create(['body' => 'Unassigned private piece']);
        app(CreateContentVersion::class)->create($other->id, $other->submitted_by, 'human_snapshot');
        foreach ([['content_item_id' => $other->id], ['content_item_id' => 999999], ['job_id' => 'unknown'], ['job_id' => 'Context-job']] as $change) {
            $response = $this->postJson('/api/v1/draft-context', array_replace($input, $change))->assertConflict();
            $this->assertStringNotContainsString('private', strtolower($response->getContent()));
        }
        DB::table('content_items')->where('id', $item->id)->update(['body' => 'Human edit']);
        $before = $this->rows();
        $this->postJson('/api/v1/draft-context', $input)->assertConflict();
        $this->assertSame($before, $this->rows());
    }

    public function test_closed_and_superseded_attempts_are_fenced(): void
    {
        [$agent, $item, $context, $job, $input] = $this->fixture();
        $jobs = app(DraftJobLifecycle::class);
        $jobs->finish($agent, $job->id, 1, 'transport_timeout');
        $this->postJson('/api/v1/draft-context', $input)->assertConflict();
        $jobs->begin($agent, $job->id);
        $this->postJson('/api/v1/draft-context', $input)->assertConflict();
        $this->postJson('/api/v1/draft-context', array_replace($input, ['attempt_number' => 2]))->assertOk();
    }

    public function test_disabled_unauthenticated_wrong_role_revoked_and_disabled_principals_are_denied(): void
    {
        $this->postJson('/api/v1/draft-context', [])->assertNotFound();
        config(['agents.draft_api_enabled' => true]);
        $this->actingAs(User::factory()->create())->postJson('/api/v1/draft-context', [])->assertUnauthorized();
        $journalist = AgentPrincipal::forceCreate(['name' => 'journalist', 'enabled' => true]);
        $issued = $journalist->createToken('fixture', ['research:write'], now()->addHour());
        Auth::forgetGuards();
        $this->withToken($issued->plainTextToken)->postJson('/api/v1/draft-context', [])->assertForbidden();
        [$agent, $item, $context, $job, $input] = $this->fixture();
        Auth::forgetGuards();
        config(['agents.draft_api_enabled' => 'true']);
        $this->postJson('/api/v1/draft-context', $input)->assertNotFound();
        config(['agents.draft_api_enabled' => true]);
        $agent->forceFill(['enabled' => false])->save();
        Auth::forgetGuards();
        $this->postJson('/api/v1/draft-context', $input)->assertForbidden();
        $agent->tokens()->delete();
        Auth::forgetGuards();
        $this->postJson('/api/v1/draft-context', $input)->assertUnauthorized();
    }

    public function test_strict_input_and_shared_rate_limit(): void
    {
        [$agent, $item, $context, $job, $input] = $this->fixture();
        foreach (['attempt_number', 'content_item_id'] as $field) {
            foreach ([null, '1', true, 1.5, 0, []] as $value) {
                $this->postJson('/api/v1/draft-context', array_replace($input, [$field => $value]))->assertUnprocessable();
            }
        }
        $this->postJson('/api/v1/draft-context', $input + ['agent' => 'underwood'])->assertUnprocessable();
        $this->postJson('/api/v1/draft-context', array_replace($input, ['job_id' => '../x']))->assertUnprocessable();
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit('draft-api:'.$agent->id, 60);
        }
        $this->postJson('/api/v1/draft-context', $input)->assertStatus(429)->assertHeader('Retry-After', '60');
    }

    public function test_service_revalidates_revoked_token_even_with_stale_authenticated_principal(): void
    {
        [$agent, $item, $context, $job, $input] = $this->fixture();
        $agent->tokens()->delete();
        $this->expectException(AuthorizationException::class);
        app(ReadDraftJobContext::class)->read($agent, $input);
    }
}
