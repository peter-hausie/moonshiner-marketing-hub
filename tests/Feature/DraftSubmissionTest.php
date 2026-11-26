<?php

namespace Tests\Feature;

use App\Services\Agents\DraftSubmission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DraftSubmissionTest extends TestCase
{
    private function input(): array
    {
        return ['job_id' => 'draft:1', 'idempotency_key' => 'result:1',
            'content_item_id' => 12, 'expected_version' => 3,
            'base_sha256' => str_repeat('a', 64), 'title' => ' Title ', 'body' => "Draft\ntext"];
    }

    public function test_canonical_contract_preserves_text_and_all_conflict_inputs(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $first = DraftSubmission::fromInput($this->input(), 'underwood', ['draft:write']);
        $this->assertSame($this->input(), $first->data);
        $this->assertSame($first->fingerprint(), DraftSubmission::fromInput(array_reverse($this->input(), true), 'underwood', ['draft:write'])->fingerprint());
        foreach (['job_id' => 'draft:2', 'idempotency_key' => 'result:2', 'content_item_id' => 13,
            'expected_version' => 4, 'base_sha256' => str_repeat('b', 64), 'title' => 'Title', 'body' => 'changed'] as $key => $value) {
            $this->assertNotSame($first->fingerprint(), DraftSubmission::fromInput(array_replace($this->input(), [$key => $value]), 'underwood', ['draft:write'])->fingerprint());
        }
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_identity_scope_and_privilege_escalation_fail_closed(): void
    {
        foreach ([['journalist', ['draft:write']], ['underwood', []], ['underwood', ['*']],
            ['underwood', ['draft:write', 'approve']], ['underwood', ['research:write']]] as [$agent, $scopes]) {
            try {
                DraftSubmission::fromInput([], $agent, $scopes);
                $this->fail('Unauthorized draft admitted.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['agent', 'scopes', 'created_by', 'status', 'approved', 'image_path', 'context', 'publication_id'] as $key) {
            $this->assertInvalid($this->input() + [$key => 'forbidden']);
        }
    }

    public function test_missing_or_malformed_conflict_and_payload_fields_are_rejected(): void
    {
        foreach (array_keys($this->input()) as $key) {
            $input = $this->input();
            unset($input[$key]);
            $this->assertInvalid($input);
        }
        foreach ([['content_item_id' => '12'], ['content_item_id' => 12.0], ['content_item_id' => true],
            ['content_item_id' => 0], ['expected_version' => 0], ['expected_version' => '3'],
            ['base_sha256' => str_repeat('A', 64)], ['base_sha256' => 'bad'],
            ['job_id' => '../job'], ['idempotency_key' => ''], ['title' => str_repeat('x', 256)],
            ['body' => str_repeat('x', 50001)], ['body' => []], ['body' => '']] as $override) {
            $this->assertInvalid(array_replace($this->input(), $override));
        }
    }

    private function assertInvalid(array $input): void
    {
        try {
            DraftSubmission::fromInput($input, 'underwood', ['draft:write']);
            $this->fail('Invalid draft admitted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
