<?php

namespace App\Services\Agents;

use App\Models\AgentPrincipal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Transactional acceptance boundary; no dispatch. */
final class AcceptDraftSubmission
{
    /** Revalidate persisted credential before any write or receipt replay. */
    public function acceptAuthenticated(AgentPrincipal $principal, array $input): object
    {
        return $this->authenticated($principal, $input, null);
    }

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
                throw new AuthorizationException('Draft submission is not permitted.');
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
        $submission = DraftSubmission::fromInput($input, $agent->name, $scopes);
        $job = DB::table('agent_jobs')->where('agent_principal_id', $agent->id)
            ->where('key_sha256', hash('sha256', $submission->data['job_id']))->lockForUpdate()->first();
        $attempt = $job ? DB::table('agent_job_attempts')->where('agent_job_id', $job->id)
            ->where('attempt_number', $number)->first() : null;
        if (! $attempt || $job->attempt_number !== $number) {
            throw new ConflictHttpException('Attempt is not current.');
        }
        if (! hash_equals($job->context_sha256, DraftContext::jobFingerprintFor($submission->data))) {
            throw new ConflictHttpException('Result does not match registered draft context.');
        }
        $existing = DB::table('draft_submission_receipts')->where('agent_principal_id', $agent->id)
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
        // below rolls back version, receipt and binding together in the outer transaction.
        $receipt = $this->accept($agent, $scopes, $input);
        if (! $existing) {
            DB::table('draft_submission_receipts')->where('id', $receipt->id)
                ->update(['agent_job_attempt_id' => $attempt->id]);
        }

        return DB::table('draft_submission_receipts')->where('id', $receipt->id)->first();
    }

    /** Trusted host-only entry; callers supply authoritative scopes. */
    public function accept(AgentPrincipal $principal, array $scopes, array $input): object
    {
        return DB::transaction(function () use ($principal, $scopes, $input): object {
            $agent = AgentPrincipal::query()->lockForUpdate()->find($principal->getKey());
            if (! $agent || ! $agent->enabled || $agent->name !== 'underwood') {
                throw new AuthorizationException('Draft submission is not permitted.');
            }
            $submission = DraftSubmission::fromInput($input, $agent->name, $scopes);
            $data = $submission->data;
            $key = hash('sha256', $data['idempotency_key']);
            $receipts = DB::table('draft_submission_receipts');
            $receipt = (clone $receipts)->where('agent_principal_id', $agent->id)
                ->where('key_sha256', $key)->first();
            if ($receipt) {
                if (! hash_equals($receipt->payload_sha256, $submission->fingerprint())) {
                    throw new ConflictHttpException('Idempotency key already accepted with different input.');
                }

                return $receipt;
            }
            $item = DB::table('content_items')->where('id', $data['content_item_id'])->lockForUpdate()->firstOrFail();
            // Lock latest version as well; cooperating version writers lock piece first.
            $latest = DB::table('content_versions')->where('content_item_id', $item->id)
                ->orderByDesc('version_number')->lockForUpdate()->first();
            if (! $latest || ! DraftContext::fromLockedRows($item, $latest)->matches($submission)) {
                throw new ConflictHttpException('Draft context has changed.');
            }
            // Append a proposal only. Never overwrite the human editable row, bind,
            // approve, or borrow its author/media snapshot for machine-generated text.
            $versionId = DB::table('content_versions')->insertGetId([
                'content_item_id' => $item->id, 'version_number' => $latest->version_number + 1,
                'title' => $data['title'], 'body' => $data['body'],
                'context' => $item->context, 'content_type' => $item->content_type,
                'image_path' => $item->image_path, 'media_snapshot' => null,
                'created_by' => null, 'provenance' => 'agent:underwood', 'created_at' => now(),
            ]);
            $id = $receipts->insertGetId([
                'agent_principal_id' => $agent->id, 'job_id' => $data['job_id'],
                'key_sha256' => $key, 'payload_sha256' => $submission->fingerprint(),
                'content_version_id' => $versionId, 'accepted_at' => now()->utc(),
            ]);

            return $receipts->where('id', $id)->first();
        }, 3);
    }
}
