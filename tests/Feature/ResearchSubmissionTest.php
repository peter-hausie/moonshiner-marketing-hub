<?php

namespace Tests\Feature;

use App\Services\Agents\ResearchSubmission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ResearchSubmissionTest extends TestCase
{
    private function input(): array
    {
        return [
            'job_id' => 'research-20261016',
            'idempotency_key' => 'observation:1',
            'source_url' => 'https://example.org/news',
            'title' => 'Source observation',
            'observed_at' => '2026-10-16T09:30:00+02:00',
        ];
    }

    public function test_contract_preserves_unknown_coverage_and_separate_dates_without_side_effects(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $submission = ResearchSubmission::fromInput($this->input() + [
            'published_at' => '2026-10-15T12:00:00+00:00',
            'event_at' => '2026-10-14T08:00:00+00:00',
        ], 'journalist', ['research:write']);

        $this->assertNull($submission->data['coverage']);
        $this->assertNull($submission->data['excerpt']);
        $this->assertNotSame($submission->data['published_at'], $submission->data['event_at']);
        $this->assertSame($this->input()['observed_at'], $submission->data['observed_at']);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_identity_and_scope_cannot_be_supplied_by_the_body(): void
    {
        foreach ([['underwood', ['research:write']], ['journalist', []], ['unknown', ['research:write']]] as [$agent, $scopes]) {
            try {
                ResearchSubmission::fromInput($this->input() + ['agent' => 'journalist', 'scopes' => ['research:write']], $agent, $scopes);
                $this->fail('Untrusted identity was admitted.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_unknown_privileged_and_nested_fields_are_rejected_not_silently_discarded(): void
    {
        foreach ([
            ['approved' => true], ['status' => 'published'], ['created_by' => 1],
            ['credentials' => []], ['agent' => 'journalist'], ['scopes' => ['research:write']],
            ['coverage' => ['state' => 'partial', 'gap_proven' => true]],
        ] as $extra) {
            $this->assertInvalid($this->input() + $extra);
        }
    }

    public function test_malformed_observations_fail_closed(): void
    {
        foreach ([
            ['source_url' => 'javascript:alert(1)'],
            ['source_url' => 'https://user:placeholder@example.org/article'],
            ['observed_at' => '2026-02-30T12:00:00+00:00'],
            ['observed_at' => '2026-10-16'],
            ['published_at' => 'yesterday'],
            ['title' => ''], ['title' => str_repeat('x', 256)],
            ['job_id' => '../job'], ['idempotency_key' => ''],
            ['coverage' => ['reason' => 'Unavailable']],
            ['coverage' => ['state' => 'gap']],
            ['coverage' => []],
            ['excerpt' => str_repeat('x', 50001)],
        ] as $override) {
            $this->assertInvalid(array_replace($this->input(), $override));
        }
    }

    public function test_retry_fingerprint_is_stable_for_object_order_and_missing_nullable_fields(): void
    {
        $input = $this->input() + ['coverage' => ['state' => 'partial', 'reason' => 'Source unavailable']];
        $first = ResearchSubmission::fromInput($input, 'journalist', ['research:write']);
        $reordered = array_reverse($input, true);
        $reordered['coverage'] = array_reverse($input['coverage'], true);
        $reordered['excerpt'] = null;
        $second = ResearchSubmission::fromInput($reordered, 'journalist', ['research:write']);
        $this->assertSame($first->fingerprint(), $second->fingerprint());
        $reordered['title'] = 'Changed observation';
        $this->assertNotSame($first->fingerprint(), ResearchSubmission::fromInput($reordered, 'journalist', ['research:write'])->fingerprint());
    }

    private function assertInvalid(array $input): void
    {
        try {
            ResearchSubmission::fromInput($input, 'journalist', ['research:write']);
            $this->fail('Invalid research input was admitted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
