<?php

namespace App\Services\Agents;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;

/** Transport only: trusted identity required; does not authorize or persist edits. */
final readonly class DraftSubmission
{
    private function __construct(public array $data) {}

    public static function fromInput(array $input, string $agent, array $scopes): self
    {
        if ($agent !== 'underwood' || $scopes !== ['draft:write']) {
            throw new AuthorizationException('Draft submission is not permitted.');
        }

        $validated = Validator::make(['submission' => $input], [
            'submission' => ['required', 'array:job_id,idempotency_key,content_item_id,expected_version,base_sha256,title,body'],
            'submission.job_id' => ['required', 'string', 'max:128', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]*\z/'],
            'submission.idempotency_key' => ['required', 'string', 'max:128', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]*\z/'],
            'submission.content_item_id' => ['required', 'integer', 'min:1'],
            'submission.expected_version' => ['required', 'integer', 'min:1'],
            'submission.base_sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'submission.title' => ['required', 'string', 'max:255'],
            'submission.body' => ['required', 'string', 'max:50000'],
        ])->after(function ($validator) use ($input): void {
            // JSON numbers only: do not coerce strings, booleans or floating IDs.
            foreach (['content_item_id', 'expected_version'] as $field) {
                if (! is_int($input[$field] ?? null)) {
                    $validator->errors()->add('submission.'.$field, 'An integer is required.');
                }
            }
        })->validate()['submission'];

        $data = [];
        foreach (['job_id', 'idempotency_key', 'content_item_id', 'expected_version', 'base_sha256', 'title', 'body'] as $field) {
            $data[$field] = $validated[$field];
        }

        return new self($data);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->data, JSON_THROW_ON_ERROR));
    }
}
