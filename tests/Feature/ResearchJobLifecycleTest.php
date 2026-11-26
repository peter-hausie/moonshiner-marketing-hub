<?php

namespace Tests\Feature;

use App\Models\AgentPrincipal;
use App\Services\Agents\ResearchJobLifecycle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ResearchJobLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function agent(): AgentPrincipal
    {
        return AgentPrincipal::forceCreate(['name' => 'journalist', 'enabled' => true]);
    }

    private function rejects(string $class, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected rejection');
        } catch (\Exception $e) {
            $this->assertInstanceOf($class, $e);
        }
    }

    public function test_registration_is_case_sensitive_idempotent_and_has_no_external_effects(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $service = new ResearchJobLifecycle;
        $agent = $this->agent();
        $job = $service->register($agent, 'Job:1', hash('sha256', 'context'));
        $this->travel(1)->hours();
        $this->assertEquals($job, $service->register($agent, 'Job:1', hash('sha256', 'context')));
        $this->rejects(ConflictHttpException::class, fn () => $service->register($agent, 'Job:1', hash('sha256', 'changed')));
        $other = $service->register($agent, 'job:1', hash('sha256', 'context'));
        $this->assertNotSame($job->id, $other->id);
        $this->assertDatabaseCount('agent_jobs', 2);
        $this->assertDatabaseCount('agent_job_attempts', 0);
        $this->assertDatabaseCount('research_items', 0);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_retry_retains_attempt_history_and_fences_late_completion(): void
    {
        $service = new ResearchJobLifecycle;
        $agent = $this->agent();
        $job = $service->register($agent, 'job', hash('sha256', 'context'));
        $first = $service->begin($agent, $job->id);
        $this->assertSame(1, $first->attempt_number);
        $this->rejects(ConflictHttpException::class, fn () => $service->begin($agent, $job->id));
        $failed = $service->finish($agent, $job->id, 1, 'transport_timeout');
        $this->assertSame('retryable', $failed->status);
        $this->travel(1)->minutes();
        $this->assertEquals($failed, $service->finish($agent, $job->id, 1, 'transport_timeout'));
        $this->assertSame(2, $service->begin($agent, $job->id)->attempt_number);
        $this->rejects(ConflictHttpException::class, fn () => $service->finish($agent, $job->id, 1));
        $done = $service->finish($agent, $job->id, 2);
        $this->assertEquals($done, $service->finish($agent, $job->id, 2));
        $this->rejects(ConflictHttpException::class, fn () => $service->finish($agent, $job->id, 2, 'cancelled'));
        $this->rejects(ConflictHttpException::class, fn () => $service->begin($agent, $job->id));
        $this->assertEquals($failed, DB::table('agent_job_attempts')->find($first->id));
        $this->assertDatabaseHas('agent_jobs', ['id' => $job->id, 'status' => 'succeeded', 'attempt_number' => 2]);
    }

    public function test_current_identity_and_ownership_are_required_but_disabled_jobs_can_be_closed(): void
    {
        $service = new ResearchJobLifecycle;
        $agent = $this->agent();
        $job = $service->register($agent, 'job', hash('sha256', 'context'));
        $foreign = AgentPrincipal::forceCreate(['name' => 'underwood', 'enabled' => true]);
        $this->rejects(AuthorizationException::class, fn () => $service->begin($foreign, $job->id));
        $this->rejects(AuthorizationException::class, fn () => $service->register($foreign, 'job', hash('sha256', 'context')));
        $service->begin($agent, $job->id);
        $this->rejects(AuthorizationException::class, fn () => $service->finish($foreign, $job->id, 1));
        DB::table('agent_principals')->where('id', $agent->id)->update(['enabled' => false]);
        $service->finish($agent, $job->id, 1, 'provider_unavailable');
        $this->rejects(AuthorizationException::class, fn () => $service->begin($agent, $job->id));
        $this->rejects(AuthorizationException::class, fn () => $service->register($agent, 'job2', hash('sha256', 'context')));
        $this->assertDatabaseCount('agent_job_attempts', 1);
    }

    public function test_only_fixed_error_codes_are_stored_and_terminal_failure_cannot_retry(): void
    {
        $service = new ResearchJobLifecycle;
        $agent = $this->agent();
        $this->rejects(ValidationException::class, fn () => $service->register($agent, 'bad job', str_repeat('a', 64)));
        $this->rejects(ValidationException::class, fn () => $service->register($agent, 'job', 'raw context'));
        $job = $service->register($agent, 'job', hash('sha256', 'context'));
        $this->rejects(ConflictHttpException::class, fn () => $service->finish($agent, $job->id, 1));
        $service->begin($agent, $job->id);
        $this->rejects(ValidationException::class, fn () => $service->finish($agent, $job->id, 1, 'raw provider response'));
        $this->assertDatabaseHas('agent_job_attempts', ['status' => 'running', 'error_code' => null]);
        $this->assertSame('failed', $service->finish($agent, $job->id, 1, 'invalid_result')->status);
        $this->rejects(ConflictHttpException::class, fn () => $service->begin($agent, $job->id));
    }

    public function test_partial_begin_and_completion_failure_roll_back_both_records(): void
    {
        $service = new ResearchJobLifecycle;
        $agent = $this->agent();
        $job = $service->register($agent, 'job', hash('sha256', 'context'));
        DB::unprepared("CREATE TRIGGER fail_job_update BEFORE UPDATE ON agent_jobs BEGIN SELECT RAISE(ABORT, 'fixture'); END");
        $this->rejects(QueryException::class, fn () => $service->begin($agent, $job->id));
        $this->assertEquals($job, DB::table('agent_jobs')->find($job->id));
        $this->assertDatabaseCount('agent_job_attempts', 0);
        DB::unprepared('DROP TRIGGER fail_job_update');
        $attempt = $service->begin($agent, $job->id);
        DB::unprepared("CREATE TRIGGER fail_job_update BEFORE UPDATE ON agent_jobs BEGIN SELECT RAISE(ABORT, 'fixture'); END");
        $this->rejects(QueryException::class, fn () => $service->finish($agent, $job->id, 1));
        $this->assertEquals($attempt, DB::table('agent_job_attempts')->find($attempt->id));
        $this->assertDatabaseHas('agent_jobs', ['id' => $job->id, 'status' => 'running']);
        DB::unprepared('DROP TRIGGER fail_job_update');
        $this->assertSame('succeeded', $service->finish($agent, $job->id, 1)->status);
        $this->rejects(QueryException::class, fn () => DB::table('agent_jobs')->where('id', $job->id)->delete());
        $this->rejects(QueryException::class, fn () => $agent->delete());
    }
}
