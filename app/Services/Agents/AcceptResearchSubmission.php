<?php

namespace App\Services\Agents;

use App\Models\AgentPrincipal;
use App\Models\ResearchItem;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Internal boundary: principal and scopes must come from the authenticated adapter. */
final class AcceptResearchSubmission
{
    /** Revalidate the authenticated credential at the persistence boundary. */
    public function acceptAuthenticated(AgentPrincipal $principal, array $input): object
    {
        return $this->authenticated($principal, $input, null);
    }

    /** Internal adapter entry: does not start or finish jobs or widen bearer scopes. */
    public function acceptAuthenticatedAttempt(AgentPrincipal $principal, array $input, int $attemptNumber): object
    {
        return $this->authenticated($principal, $input, $attemptNumber);
    }

    private function authenticated(AgentPrincipal $principal, array $input, ?int $attemptNumber): object
    {
        return DB::transaction(function () use ($principal, $input, $attemptNumber): object {
            // Same lock order as acceptance: principal first, credential second.
            $agent = AgentPrincipal::query()->lockForUpdate()->find($principal->getKey());
            $authenticated = $principal->currentAccessToken();
            $token = $agent && $authenticated instanceof PersonalAccessToken
                ? $agent->tokens()->whereKey($authenticated->getKey())->lockForUpdate()->first()
                : null;
            $maxAge = config('sanctum.expiration');
            if (! $token || ! hash_equals($authenticated->token, $token->token)
                || $token->expires_at === null || ! $token->expires_at->isFuture()
                || ($maxAge && (! $token->created_at || $token->created_at->lte(now()->subMinutes($maxAge))))
                || ! is_array($token->abilities)) {
                throw new AuthorizationException('Research submission is not permitted.');
            }

            return $attemptNumber === null
                ? $this->accept($agent, $token->abilities, $input)
                : $this->acceptAttempt($agent, $token->abilities, $input, $attemptNumber);
        }, 3);
    }

    private function acceptAttempt(AgentPrincipal $agent, array $scopes, array $input, int $number): object
    {
        // Authentication already holds principal/token locks. Lifecycle writers
        // use the same principal-first order, fencing finish/retry against acceptance.
        $submission = ResearchSubmission::fromInput($input, $agent->name, $scopes);
        $job = DB::table('agent_jobs')->where('agent_principal_id', $agent->id)
            ->where('key_sha256', hash('sha256', $submission->data['job_id']))->lockForUpdate()->first();
        $attempt = $job ? DB::table('agent_job_attempts')->where('agent_job_id', $job->id)
            ->where('attempt_number', $number)->first() : null;
        if (! $attempt || $job->attempt_number !== $number) {
            throw new ConflictHttpException('Attempt is not current.');
        }
        $existing = DB::table('research_submission_receipts')->where('agent_principal_id', $agent->id)
            ->where('key_sha256', hash('sha256', $submission->data['idempotency_key']))->first();
        if ($existing) {
            // Never adopt a legacy/unbound receipt or move evidence between retries.
            if ($existing->agent_job_attempt_id !== $attempt->id) {
                throw new ConflictHttpException('Receipt belongs to another attempt.');
            }
        } elseif ($job->status !== 'running' || $attempt->status !== 'running') {
            throw new ConflictHttpException('Attempt is not accepting results.');
        }
        // Revalidates enabled principal/scopes and exact replay payload. Any failure
        // below rolls back item, receipt and binding together in the outer transaction.
        $receipt = $this->accept($agent, $scopes, $input);
        if (! $existing) {
            DB::table('research_submission_receipts')->where('id', $receipt->id)
                ->update(['agent_job_attempt_id' => $attempt->id]);
        }

        return DB::table('research_submission_receipts')->where('id', $receipt->id)->first();
    }

    public function accept(AgentPrincipal $principal, array $scopes, array $input): object
    {
        return DB::transaction(function () use ($principal, $scopes, $input): object {
            // Serialize submissions per principal, including absent receipt rows.
            $agent = AgentPrincipal::query()->lockForUpdate()->find($principal->getKey());
            if (! $agent || ! $agent->enabled || $agent->name !== 'journalist'
                || $scopes === [] || array_diff($scopes, $agent->allowedAbilities()) !== []) {
                throw new AuthorizationException('Research submission is not permitted.');
            }
            $submission = ResearchSubmission::fromInput($input, $agent->name, $scopes);
            $data = $submission->data;
            $key = hash('sha256', $data['idempotency_key']);
            $receipts = DB::table('research_submission_receipts');
            $receipt = (clone $receipts)->where('agent_principal_id', $agent->id)
                ->where('key_sha256', $key)->first();
            if ($receipt) {
                if (! hash_equals($receipt->payload_sha256, $submission->fingerprint())) {
                    throw new ConflictHttpException('Idempotency key already accepted with different input.');
                }

                return $receipt;
            }
            $item = new ResearchItem;
            $item->forceFill([
                'source_url' => $data['source_url'], 'title' => $data['title'],
                'excerpt' => $data['excerpt'], 'coverage' => $data['coverage'],
                'observed_at' => CarbonImmutable::parse($data['observed_at'])->utc(),
                'published_at' => $data['published_at'] === null ? null : CarbonImmutable::parse($data['published_at'])->utc(),
                'event_at' => $data['event_at'] === null ? null : CarbonImmutable::parse($data['event_at'])->utc(),
                'provenance' => 'agent:journalist', 'created_by' => null,
            ])->save();
            $id = $receipts->insertGetId([
                'agent_principal_id' => $agent->id, 'job_id' => $data['job_id'],
                'key_sha256' => $key, 'payload_sha256' => $submission->fingerprint(),
                'research_item_id' => $item->id, 'accepted_at' => now()->utc(),
            ]);

            return $receipts->where('id', $id)->first();
        }, 3);
    }
}
