<?php

namespace App\Services\Agents;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;

/**
 * Validated transport contract only; no persistence or token authentication.
 * Identity/scopes MUST come from a trusted adapter, never request-body fields.
 */
final readonly class ResearchSubmission
{
    private function __construct(public array $data) {}

    public static function fromInput(array $input, string $agent, array $scopes): self
    {
        if ($agent !== 'journalist' || ! in_array('research:write', $scopes, true)) {
            throw new AuthorizationException('Research submission is not permitted.');
        }

        $validated = Validator::make(['submission' => $input], [
            'submission' => ['required', 'array:job_id,idempotency_key,source_url,title,excerpt,observed_at,published_at,event_at,coverage'],
            'submission.job_id' => ['required', 'string', 'max:128', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]*\z/'],
            'submission.idempotency_key' => ['required', 'string', 'max:128', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]*\z/'],
            'submission.source_url' => ['required', 'string', 'max:4096', 'url:http,https'],
            'submission.title' => ['required', 'string', 'max:255'],
            'submission.excerpt' => ['nullable', 'string', 'max:50000'],
            'submission.observed_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'submission.published_at' => ['nullable', 'date_format:Y-m-d\TH:i:sP'],
            'submission.event_at' => ['nullable', 'date_format:Y-m-d\TH:i:sP'],
            'submission.coverage' => ['nullable', 'array:state,reason', 'min:1'],
            'submission.coverage.state' => ['required_with:submission.coverage', 'in:unknown,partial,complete'],
            'submission.coverage.reason' => ['nullable', 'string', 'max:2000'],
        ])->after(function ($validator) use ($input): void {
            // A source is evidence, not a credential-bearing fetch instruction.
            $url = $input['source_url'] ?? null;
            if (is_string($url) && ($parts = parse_url($url)) !== false
                && (isset($parts['user']) || isset($parts['pass']))) {
                $validator->errors()->add('submission.source_url', 'Source URL must not contain user information.');
            }
        })->validate()['submission'];

        // Fixed field order and explicit nullable fields make retries independent
        // of JSON object order. This digest does NOT implement durable idempotency.
        $data = [];
        foreach (['job_id', 'idempotency_key', 'source_url', 'title', 'excerpt', 'observed_at', 'published_at', 'event_at'] as $key) {
            $data[$key] = $validated[$key] ?? null;
        }
        $coverage = $validated['coverage'] ?? null;
        $data['coverage'] = $coverage === null ? null : [
            'state' => $coverage['state'],
            'reason' => $coverage['reason'] ?? null,
        ];

        return new self($data);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->data, JSON_THROW_ON_ERROR));
    }
}
