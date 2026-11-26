<?php

namespace App\Services\Agents;

use App\Models\AgentPrincipal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Job-bound context read; never registers, starts or modifies a job. */
final class ReadDraftJobContext
{
    public function read(AgentPrincipal $principal, array $input): array
    {
        Validator::make(['input' => $input], [
            'input' => ['required', 'array:job_id,attempt_number,content_item_id'],
            'input.job_id' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_.:-]*\z/'],
        ])->validate();
        foreach (['attempt_number', 'content_item_id'] as $field) {
            if (! isset($input[$field]) || ! is_int($input[$field]) || $input[$field] < 1) {
                throw ValidationException::withMessages([$field => 'A positive integer is required.']);
            }
        }

        return DB::transaction(function () use ($principal, $input): array {
            // Same order as result acceptance and lifecycle/credential writers.
            $agent = AgentPrincipal::query()->lockForUpdate()->find($principal->getKey());
            $authenticated = $principal->currentAccessToken();
            $token = $agent && $authenticated instanceof PersonalAccessToken
                ? $agent->tokens()->whereKey($authenticated->getKey())->lockForUpdate()->first() : null;
            $maxAge = config('sanctum.expiration');
            if (! $agent || ! $agent->enabled || $agent->name !== 'underwood'
                || ! $token || ! hash_equals($authenticated->token, $token->token)
                || $token->expires_at === null || ! $token->expires_at->isFuture()
                || ($maxAge && (! $token->created_at || $token->created_at->lte(now()->subMinutes($maxAge))))
                || ! is_array($token->abilities)
                || array_diff($token->abilities, $agent->allowedAbilities()) !== []
                || ! in_array('draft:write', $token->abilities, true)) {
                throw new AuthorizationException('Draft context is not permitted.');
            }
            $job = DB::table('agent_jobs')->where('agent_principal_id', $agent->id)
                ->where('key_sha256', hash('sha256', $input['job_id']))->lockForUpdate()->first();
            $attempt = $job ? DB::table('agent_job_attempts')->where('agent_job_id', $job->id)
                ->where('attempt_number', $input['attempt_number'])->first() : null;
            if (! $attempt || $job->attempt_number !== $input['attempt_number']
                || $job->status !== 'running' || $attempt->status !== 'running') {
                throw new ConflictHttpException('Draft context is not current.');
            }
            $item = DB::table('content_items')->where('id', $input['content_item_id'])->lockForUpdate()->first();
            $latest = $item ? DB::table('content_versions')->where('content_item_id', $item->id)
                ->orderByDesc('version_number')->lockForUpdate()->first() : null;
            if (! $latest) {
                throw new ConflictHttpException('Draft context is not current.');
            }
            $context = DraftContext::fromLockedRows($item, $latest);
            if (! hash_equals($job->context_sha256, $context->jobFingerprint())) {
                throw new ConflictHttpException('Draft context is not current.');
            }

            return $context->data + ['job_id' => $job->job_id, 'attempt_number' => $attempt->attempt_number];
        }, 3);
    }
}
