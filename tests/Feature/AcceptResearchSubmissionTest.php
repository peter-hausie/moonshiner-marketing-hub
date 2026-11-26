<?php

namespace Tests\Feature;

use App\Models\AgentPrincipal;
use App\Models\ResearchItem;
use App\Services\Agents\AcceptResearchSubmission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class AcceptResearchSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function agent(): AgentPrincipal
    {
        return AgentPrincipal::forceCreate(['name' => 'journalist', 'enabled' => true]);
    }

    private function input(): array
    {
        return ['job_id' => 'research-1', 'idempotency_key' => 'Item:1',
            'source_url' => 'https://example.org/news', 'title' => 'Observation',
            'observed_at' => '2026-10-16T10:00:00+02:00'];
    }

    private function accept(AgentPrincipal $agent, array $input): object
    {
        return app(AcceptResearchSubmission::class)->accept($agent, ['research:write'], $input);
    }

    public function test_atomic_acceptance_and_retry_preserve_receipt_and_separate_dates(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $agent = $this->agent();
        $input = $this->input() + ['published_at' => '2026-10-15T12:00:00+02:00', 'event_at' => '2026-10-14T09:00:00+00:00'];
        $receipt = $this->accept($agent, $input);
        $this->travel(1)->hours();
        $this->assertEquals($receipt, $this->accept($agent, array_reverse($input, true)));
        $item = ResearchItem::findOrFail($receipt->research_item_id);
        $this->assertSame('2026-10-16 08:00:00', $item->observed_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-15 10:00:00', $item->published_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-14 09:00:00', $item->event_at->format('Y-m-d H:i:s'));
        $this->assertNull($item->coverage);
        $this->assertNull($item->created_by);
        $this->assertSame('agent:journalist', $item->provenance);
        $this->assertDatabaseCount('research_items', 1);
        $this->assertDatabaseCount('research_submission_receipts', 1);
        $this->assertDatabaseCount('content_items', 0);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_changed_payload_or_job_conflicts_without_overwriting_evidence(): void
    {
        $agent = $this->agent();
        $receipt = $this->accept($agent, $this->input());
        foreach (['title' => 'Changed', 'job_id' => 'another-job'] as $key => $value) {
            try {
                $this->accept($agent, array_replace($this->input(), [$key => $value]));
                $this->fail('Conflict accepted');
            } catch (ConflictHttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }
        $this->assertEquals($receipt, $this->accept($agent, $this->input()));
        $this->assertDatabaseCount('research_items', 1);
        $this->assertDatabaseHas('research_items', ['title' => 'Observation']);
    }

    public function test_key_identity_is_case_sensitive(): void
    {
        $agent = $this->agent();
        $first = $this->accept($agent, $this->input());
        $second = $this->accept($agent, array_replace($this->input(), ['idempotency_key' => 'item:1']));
        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('research_items', 2);
    }

    public function test_stale_disabled_principal_and_excess_scopes_cannot_replay(): void
    {
        $agent = $this->agent();
        $this->accept($agent, $this->input());
        foreach ([['research:write', '*'], []] as $scopes) {
            try {
                app(AcceptResearchSubmission::class)->accept($agent, $scopes, $this->input());
                $this->fail('Invalid scope accepted');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        AgentPrincipal::whereKey($agent->id)->update(['enabled' => false]);
        $this->expectException(AuthorizationException::class);
        $this->accept($agent, $this->input());
    }

    public function test_authenticated_acceptance_rechecks_revocation_expiry_and_scope_before_replay(): void
    {
        $agent = $this->agent();
        $service = app(AcceptResearchSubmission::class);
        foreach (['deleted', 'expired', 'aged', 'scope', 'digest'] as $change) {
            $issued = $agent->createToken('test', ['research:write'], now()->addHour());
            $token = $issued->accessToken;
            $agent->withAccessToken($token);
            $receipt = $service->acceptAuthenticated($agent, $this->input());
            // Mutate storage without refreshing the already-authenticated model.
            match ($change) {
                'deleted' => DB::table('personal_access_tokens')->where('id', $token->id)->delete(),
                'expired' => DB::table('personal_access_tokens')->where('id', $token->id)->update(['expires_at' => now()->subSecond()]),
                'aged' => DB::table('personal_access_tokens')->where('id', $token->id)->update(['created_at' => now()->subDays(2)]),
                'scope' => DB::table('personal_access_tokens')->where('id', $token->id)->update(['abilities' => '[]']),
                'digest' => DB::table('personal_access_tokens')->where('id', $token->id)->update(['token' => hash('sha256', 'inert-replacement')]),
            };
            foreach ([$this->input(), array_replace($this->input(), ['idempotency_key' => 'new-item'])] as $input) {
                try {
                    $service->acceptAuthenticated($agent, $input);
                    $this->fail('Stale credential accepted');
                } catch (AuthorizationException) {
                    $this->assertDatabaseCount('research_items', 1);
                    $this->assertDatabaseCount('research_submission_receipts', 1);
                }
            }
            $this->assertEquals($receipt, DB::table('research_submission_receipts')->first());
        }
    }

    public function test_authenticated_acceptance_rejects_foreign_and_missing_token(): void
    {
        $agent = $this->agent();
        $other = AgentPrincipal::forceCreate(['name' => 'underwood', 'enabled' => true]);
        $token = $other->createToken('test', ['draft:write'], now()->addHour())->accessToken;
        foreach ([null, $token] as $credential) {
            $agent->withAccessToken($credential);
            try {
                app(AcceptResearchSubmission::class)->acceptAuthenticated($agent, $this->input());
                $this->fail('Unbound credential accepted');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('research_items', 0);
            }
        }
    }

    public function test_receipt_failure_rolls_back_research_and_allows_retry(): void
    {
        $agent = $this->agent();
        DB::unprepared("CREATE TRIGGER reject_receipt BEFORE INSERT ON research_submission_receipts BEGIN SELECT RAISE(ABORT, 'simulated receipt failure'); END");
        try {
            $this->accept($agent, $this->input());
            $this->fail('Receipt failure expected');
        } catch (QueryException) {
            $this->assertDatabaseCount('research_items', 0);
            $this->assertDatabaseCount('research_submission_receipts', 0);
        } finally {
            DB::unprepared('DROP TRIGGER reject_receipt');
        }
        $this->accept($agent, $this->input());
        $this->assertDatabaseCount('research_items', 1);
    }
}
