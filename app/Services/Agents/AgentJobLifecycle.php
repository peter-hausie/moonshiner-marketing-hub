<?php

namespace App\Services\Agents;

use App\Models\AgentPrincipal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Trusted host bookkeeping only: neither dispatch nor agent authentication. */
abstract class AgentJobLifecycle
{
    abstract protected function agentName(): string;

    public function register(AgentPrincipal $principal, string $jobId, string $contextSha256): object
    {
        Validator::make(['job_id' => $jobId, 'context' => $contextSha256], [
            'job_id' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_.:-]*\z/'],
            'context' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
        ])->validate();

        return DB::transaction(function () use ($principal, $jobId, $contextSha256): object {
            $agent = AgentPrincipal::query()->lockForUpdate()->find($principal->getKey());
            if (! $agent || ! $agent->enabled || $agent->name !== $this->agentName()) {
                throw new AuthorizationException('Agent job is not permitted.');
            }
            $jobs = DB::table('agent_jobs');
            $key = hash('sha256', $jobId);
            $existing = (clone $jobs)->where('agent_principal_id', $agent->id)->where('key_sha256', $key)->first();
            if ($existing) {
                if (! hash_equals($existing->context_sha256, $contextSha256)) {
                    throw new ConflictHttpException('Job context cannot be replaced.');
                }

                return $existing;
            }
            $id = $jobs->insertGetId([
                'agent_principal_id' => $agent->id, 'job_id' => $jobId,
                'key_sha256' => $key, 'context_sha256' => $contextSha256,
                'status' => 'pending', 'attempt_number' => 0,
                'created_at' => now()->utc(), 'updated_at' => now()->utc(),
            ]);

            return $jobs->where('id', $id)->first();
        }, 3);
    }

    public function begin(AgentPrincipal $principal, int $jobId): object
    {
        return DB::transaction(function () use ($principal, $jobId): object {
            $job = $this->lockedJob($principal, $jobId, true);
            if (! in_array($job->status, ['pending', 'retryable'], true)) {
                throw new ConflictHttpException('Job cannot start another attempt.');
            }
            $number = $job->attempt_number + 1;
            $id = DB::table('agent_job_attempts')->insertGetId([
                'agent_job_id' => $job->id, 'attempt_number' => $number,
                'status' => 'running', 'started_at' => now()->utc(),
            ]);
            DB::table('agent_jobs')->where('id', $job->id)->update([
                'status' => 'running', 'attempt_number' => $number, 'updated_at' => now()->utc(),
            ]);

            return DB::table('agent_job_attempts')->where('id', $id)->first();
        }, 3);
    }

    /** Attempt number fences late completions after an explicitly recorded retry. */
    public function finish(AgentPrincipal $principal, int $jobId, int $number, ?string $errorCode = null): object
    {
        Validator::make(['error' => $errorCode], [
            'error' => ['nullable', 'in:transport_timeout,provider_unavailable,invalid_result,cancelled'],
        ])->validate();
        $status = match ($errorCode) {
            null => 'succeeded',
            'transport_timeout', 'provider_unavailable' => 'retryable',
            default => 'failed',
        };

        return DB::transaction(function () use ($principal, $jobId, $number, $errorCode, $status): object {
            // Operators can close an in-flight attempt after principal disablement.
            $job = $this->lockedJob($principal, $jobId, false);
            $attempt = DB::table('agent_job_attempts')->where('agent_job_id', $job->id)
                ->where('attempt_number', $number)->first();
            if (! $attempt || $job->attempt_number !== $number) {
                throw new ConflictHttpException('Attempt is not current.');
            }
            if ($attempt->status !== 'running') {
                if ($attempt->status === $status && $attempt->error_code === $errorCode) {
                    return $attempt;
                }
                throw new ConflictHttpException('Attempt outcome cannot be replaced.');
            }
            DB::table('agent_job_attempts')->where('id', $attempt->id)->update([
                'status' => $status, 'error_code' => $errorCode, 'finished_at' => now()->utc(),
            ]);
            DB::table('agent_jobs')->where('id', $job->id)->update([
                'status' => $status, 'updated_at' => now()->utc(),
            ]);

            return DB::table('agent_job_attempts')->where('id', $attempt->id)->first();
        }, 3);
    }

    private function lockedJob(AgentPrincipal $principal, int $id, bool $requireEnabled): object
    {
        $agent = AgentPrincipal::query()->lockForUpdate()->find($principal->getKey());
        $job = DB::table('agent_jobs')->where('id', $id)->lockForUpdate()->first();
        if (! $agent || ! $job || $job->agent_principal_id !== $agent->id
            || $agent->name !== $this->agentName() || ($requireEnabled && ! $agent->enabled)) {
            throw new AuthorizationException('Agent job is not permitted.');
        }

        return $job;
    }
}
