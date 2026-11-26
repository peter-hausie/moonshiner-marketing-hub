<?php

namespace Tests\Feature;

use App\Models\AgentPrincipal;
use App\Models\ContentItem;
use App\Services\Agents\AcceptDraftSubmission;
use App\Services\Agents\DraftContext;
use App\Services\Agents\DraftJobLifecycle;
use App\Services\Content\CreateContentVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class DraftAttemptReceiptTest extends TestCase
{
    use RefreshDatabase;

    private array $input;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $agent = AgentPrincipal::forceCreate(['name' => 'underwood', 'enabled' => true]);
        $agent->withAccessToken($agent->createToken('test', ['draft:write'], now()->addHour())->accessToken);
        $item = ContentItem::factory()->create();
        app(CreateContentVersion::class)->create($item->id, $item->submitted_by, 'human_snapshot');
        $context = DraftContext::forContentItem($item->id);
        $this->input = array_intersect_key($context->data, array_flip(['content_item_id', 'expected_version', 'base_sha256']))
            + ['job_id' => 'Job:1', 'idempotency_key' => 'Item:1', 'title' => 'Proposal', 'body' => 'Draft'];
        $jobs = app(DraftJobLifecycle::class);
        $job = $jobs->register($agent, 'Job:1', $context->jobFingerprint());
        $attempt = $jobs->begin($agent, $job->id);

        return [$agent, $jobs, $job, $attempt];
    }

    private function accept(AgentPrincipal $agent, int $number = 1, array $overrides = []): object
    {
        return app(AcceptDraftSubmission::class)->acceptAuthenticatedAttempt($agent, array_replace($this->input, $overrides), $number);
    }

    public function test_bound_replay_is_read_only_after_completion_but_new_results_are_rejected(): void
    {
        [$agent, $jobs, $job, $attempt] = $this->fixture();
        $receipt = $this->accept($agent);
        $this->assertSame($attempt->id, $receipt->agent_job_attempt_id);
        $this->assertDatabaseHas('agent_jobs', ['id' => $job->id, 'status' => 'running']);
        $jobs->finish($agent, $job->id, 1);
        $this->assertEquals($receipt, $this->accept($agent));
        foreach ([['idempotency_key' => 'Item:2'], ['title' => 'Altered']] as $change) {
            try {
                $this->accept($agent, 1, $change);
                $this->fail('Closed or altered result accepted');
            } catch (ConflictHttpException) {
                $this->assertDatabaseCount('content_versions', 2);
            }
        }
        Http::assertNothingSent();
    }

    public function test_retry_fences_late_results_and_cannot_reassign_old_receipt(): void
    {
        [$agent, $jobs, $job] = $this->fixture();
        $receipt = $this->accept($agent);
        $jobs->finish($agent, $job->id, 1, 'transport_timeout');
        $second = $jobs->begin($agent, $job->id);
        foreach ([[1, []], [1, ['idempotency_key' => 'late']], [2, []]] as [$number, $change]) {
            try {
                $this->accept($agent, $number, $change);
                $this->fail('Stale/rebound result accepted');
            } catch (ConflictHttpException) {
                $this->assertEquals($receipt, DB::table('draft_submission_receipts')->first());
            }
        }
        $this->assertSame(2, $second->attempt_number);
        $this->assertDatabaseCount('content_versions', 2);
    }

    public function test_unknown_case_changed_foreign_and_invalid_attempts_do_not_write(): void
    {
        [$agent] = $this->fixture();
        foreach ([[0, []], [2, []], [1, ['job_id' => 'job:1']], [1, ['job_id' => 'unknown']]] as [$number, $change]) {
            try {
                $this->accept($agent, $number, $change);
                $this->fail('Invalid job accepted');
            } catch (ConflictHttpException) {
                $this->assertDatabaseCount('content_versions', 1);
            }
        }
        // A different persisted principal cannot use this job, even with a stale name.
        $other = AgentPrincipal::forceCreate(['name' => 'journalist', 'enabled' => true]);
        $other->withAccessToken($other->createToken('test', ['research:write'], now()->addHour())->accessToken);
        $other->name = 'underwood';
        $this->expectException(AuthorizationException::class);
        $this->accept($other);
    }

    public function test_legacy_receipt_is_not_retroactively_bound_and_revoked_token_cannot_replay(): void
    {
        [$agent] = $this->fixture();
        $receipt = $this->accept($agent);
        DB::table('draft_submission_receipts')->where('id', $receipt->id)->update(['agent_job_attempt_id' => null]);
        try {
            $this->accept($agent);
            $this->fail('Legacy receipt adopted');
        } catch (ConflictHttpException) {
            $this->assertNull(DB::table('draft_submission_receipts')->first()->agent_job_attempt_id);
        }
        $agent->currentAccessToken()->delete();
        $this->expectException(AuthorizationException::class);
        $this->accept($agent);
    }

    public function test_binding_failure_rolls_back_item_and_receipt_then_retry_succeeds(): void
    {
        [$agent, , , $attempt] = $this->fixture();
        DB::unprepared("CREATE TRIGGER reject_binding BEFORE UPDATE ON draft_submission_receipts BEGIN SELECT RAISE(ABORT, 'simulated binding failure'); END");
        try {
            $this->accept($agent);
            $this->fail('Binding failure expected');
        } catch (QueryException) {
            $this->assertDatabaseCount('content_versions', 1);
            $this->assertDatabaseCount('draft_submission_receipts', 0);
            $this->assertDatabaseHas('agent_job_attempts', ['id' => $attempt->id, 'status' => 'running']);
        } finally {
            DB::unprepared('DROP TRIGGER reject_binding');
        }
        $this->assertSame($attempt->id, $this->accept($agent)->agent_job_attempt_id);
        $this->expectException(QueryException::class);
        DB::table('agent_job_attempts')->where('id', $attempt->id)->delete();
    }

    public function test_changed_registered_tuple_and_fresh_human_edits_are_rejected(): void
    {
        [$agent] = $this->fixture();
        foreach ([['expected_version' => 2], ['base_sha256' => str_repeat('a', 64)], ['content_item_id' => 999]] as $change) {
            try {
                $this->accept($agent, 1, $change);
                $this->fail('Different registered context accepted');
            } catch (ConflictHttpException $error) {
                $this->assertSame('Result does not match registered draft context.', $error->getMessage());
            }
        }
        DB::table('content_items')->where('id', $this->input['content_item_id'])->update(['body' => 'New human edit']);
        try {
            $this->accept($agent);
            $this->fail('Stale current context accepted');
        } catch (ConflictHttpException $error) {
            $this->assertSame('Draft context has changed.', $error->getMessage());
            $this->assertDatabaseCount('content_versions', 1);
            $this->assertDatabaseCount('draft_submission_receipts', 0);
        }
    }

    public function test_retry_without_prior_result_accepts_current_attempt_and_preserves_binding_on_rollback(): void
    {
        [$agent, $jobs, $job] = $this->fixture();
        $jobs->finish($agent, $job->id, 1, 'transport_timeout');
        $attempt = $jobs->begin($agent, $job->id);
        $receipt = $this->accept($agent, 2);
        $this->assertSame($attempt->id, $receipt->agent_job_attempt_id);
        $migration = require database_path('migrations/2026_10_17_045000_bind_draft_receipts_to_attempts.php');
        try {
            $migration->down();
            $this->fail('Attempt history discarded');
        } catch (\RuntimeException $error) {
            $this->assertSame('Attempt-bound draft receipts prevent rollback.', $error->getMessage());
            $this->assertEquals($receipt, DB::table('draft_submission_receipts')->first());
        }
    }
}
