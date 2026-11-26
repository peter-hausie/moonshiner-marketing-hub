<?php

namespace Tests\Feature;

use App\Models\AgentPrincipal;
use App\Services\Agents\AcceptResearchSubmission;
use App\Services\Agents\ResearchJobLifecycle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ResearchAttemptReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $agent = AgentPrincipal::forceCreate(['name' => 'journalist', 'enabled' => true]);
        $agent->withAccessToken($agent->createToken('test', ['research:write'], now()->addHour())->accessToken);
        $jobs = app(ResearchJobLifecycle::class);
        $job = $jobs->register($agent, 'Job:1', hash('sha256', 'context'));
        $attempt = $jobs->begin($agent, $job->id);

        return [$agent, $jobs, $job, $attempt];
    }

    private function accept(AgentPrincipal $agent, int $number = 1, array $overrides = []): object
    {
        return app(AcceptResearchSubmission::class)->acceptAuthenticatedAttempt($agent, array_replace([
            'job_id' => 'Job:1', 'idempotency_key' => 'Item:1',
            'source_url' => 'https://example.org/news', 'title' => 'Evidence',
            'observed_at' => '2026-10-16T11:00:00+00:00',
        ], $overrides), $number);
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
                $this->assertDatabaseCount('research_items', 1);
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
                $this->assertEquals($receipt, DB::table('research_submission_receipts')->first());
            }
        }
        $next = $this->accept($agent, 2, ['idempotency_key' => 'Item:2']);
        $this->assertSame($second->id, $next->agent_job_attempt_id);
        $this->assertDatabaseCount('research_items', 2);
    }

    public function test_unknown_case_changed_foreign_and_invalid_attempts_do_not_write(): void
    {
        [$agent] = $this->fixture();
        foreach ([[0, []], [2, []], [1, ['job_id' => 'job:1']], [1, ['job_id' => 'unknown']]] as [$number, $change]) {
            try {
                $this->accept($agent, $number, $change);
                $this->fail('Invalid job accepted');
            } catch (ConflictHttpException) {
                $this->assertDatabaseCount('research_items', 0);
            }
        }
        // A different persisted principal cannot use this job, even with a stale name.
        $other = AgentPrincipal::forceCreate(['name' => 'underwood', 'enabled' => true]);
        $other->withAccessToken($other->createToken('test', ['draft:write'], now()->addHour())->accessToken);
        $other->name = 'journalist';
        $this->expectException(AuthorizationException::class);
        $this->accept($other);
    }

    public function test_legacy_receipt_is_not_retroactively_bound_and_revoked_token_cannot_replay(): void
    {
        [$agent] = $this->fixture();
        $receipt = $this->accept($agent);
        DB::table('research_submission_receipts')->where('id', $receipt->id)->update(['agent_job_attempt_id' => null]);
        try {
            $this->accept($agent);
            $this->fail('Legacy receipt adopted');
        } catch (ConflictHttpException) {
            $this->assertNull(DB::table('research_submission_receipts')->first()->agent_job_attempt_id);
        }
        $agent->currentAccessToken()->delete();
        $this->expectException(AuthorizationException::class);
        $this->accept($agent);
    }

    public function test_binding_failure_rolls_back_item_and_receipt_then_retry_succeeds(): void
    {
        [$agent, , , $attempt] = $this->fixture();
        DB::unprepared("CREATE TRIGGER reject_binding BEFORE UPDATE ON research_submission_receipts BEGIN SELECT RAISE(ABORT, 'simulated binding failure'); END");
        try {
            $this->accept($agent);
            $this->fail('Binding failure expected');
        } catch (QueryException) {
            $this->assertDatabaseCount('research_items', 0);
            $this->assertDatabaseCount('research_submission_receipts', 0);
            $this->assertDatabaseHas('agent_job_attempts', ['id' => $attempt->id, 'status' => 'running']);
        } finally {
            DB::unprepared('DROP TRIGGER reject_binding');
        }
        $this->assertSame($attempt->id, $this->accept($agent)->agent_job_attempt_id);
        $this->expectException(QueryException::class);
        DB::table('agent_job_attempts')->where('id', $attempt->id)->delete();
    }
}
