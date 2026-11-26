<?php

namespace Tests\Feature;

use App\Models\AgentPrincipal;
use App\Models\ContentItem;
use App\Services\Agents\AcceptDraftSubmission;
use App\Services\Agents\DraftContext;
use App\Services\Content\CreateContentVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\TransientToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class AcceptDraftSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $agent = AgentPrincipal::forceCreate(['name' => 'underwood', 'enabled' => true]);
        $item = ContentItem::factory()->create();
        app(CreateContentVersion::class)->create($item->id, $item->submitted_by, 'human_snapshot');
        $context = DraftContext::forContentItem($item->id);
        $input = array_intersect_key($context->data, array_flip(['content_item_id', 'expected_version', 'base_sha256']))
            + ['job_id' => 'draft:1', 'idempotency_key' => 'Result:1', 'title' => 'Proposal', 'body' => "Exact\n draft "];

        return [$agent, $item, $input];
    }

    private function accept(AgentPrincipal $agent, array $input): object
    {
        return app(AcceptDraftSubmission::class)->accept($agent, ['draft:write'], $input);
    }

    public function test_proposal_and_receipt_are_atomic_and_replay_survives_later_human_edits(): void
    {
        [$agent, $item, $input] = $this->fixture();
        Bus::fake();
        $before = DB::table('content_items')->get()->toJson();
        $receipt = $this->accept($agent, $input);
        $version = DB::table('content_versions')->find($receipt->content_version_id);
        $this->assertSame(2, $version->version_number);
        $this->assertSame($input['body'], $version->body);
        $this->assertNull($version->created_by);
        $this->assertNull($version->media_snapshot);
        $this->assertSame('agent:underwood', $version->provenance);
        $this->assertSame($agent->id, $receipt->agent_principal_id);
        $this->assertSame($before, DB::table('content_items')->get()->toJson());
        DB::table('content_items')->where('id', $item->id)->update(['body' => 'Later human text']);
        app(CreateContentVersion::class)->create($item->id, $item->submitted_by, 'human_snapshot');
        $this->assertEquals($receipt, $this->accept($agent, array_reverse($input, true)));
        $this->assertDatabaseCount('content_versions', 3);
        $this->assertDatabaseCount('draft_submission_receipts', 1);
        $this->assertDatabaseCount('publications', 0);
        Bus::assertNothingDispatched();
    }

    public function test_unsnapshotted_edit_and_new_version_each_reject_stale_submission(): void
    {
        [$agent, $item, $input] = $this->fixture();
        foreach (['edit', 'version'] as $case) {
            if ($case === 'edit') {
                DB::table('content_items')->where('id', $item->id)->update(['body' => 'Human change']);
            } else {
                $input['base_sha256'] = DraftContext::forContentItem($item->id)->data['base_sha256'];
                app(CreateContentVersion::class)->create($item->id, $item->submitted_by, 'human_snapshot');
            }
            try {
                $this->accept($agent, $input);
                $this->fail('Stale proposal accepted.');
            } catch (ConflictHttpException $error) {
                $this->assertSame('Draft context has changed.', $error->getMessage());
            }
        }
        $this->assertDatabaseCount('content_versions', 2);
        $this->assertDatabaseCount('draft_submission_receipts', 0);
    }

    public function test_changed_replay_and_persisted_disabled_identity_are_rejected(): void
    {
        [$agent, $item, $input] = $this->fixture();
        $this->accept($agent, $input);
        try {
            $this->accept($agent, array_replace($input, ['body' => 'Different']));
            $this->fail('Changed replay accepted.');
        } catch (ConflictHttpException) {
            $this->assertDatabaseCount('content_versions', 2);
        }
        DB::table('agent_principals')->where('id', $agent->id)->update(['enabled' => false]);
        $this->expectException(AuthorizationException::class);
        $this->accept($agent, $input);
    }

    public function test_receipt_failure_rolls_back_version_and_allows_retry(): void
    {
        [$agent, $item, $input] = $this->fixture();
        DB::unprepared("CREATE TRIGGER reject_draft_receipt BEFORE INSERT ON draft_submission_receipts BEGIN SELECT RAISE(ABORT, 'forced receipt failure'); END");
        try {
            $this->accept($agent, $input);
            $this->fail('Forced failure ignored.');
        } catch (QueryException) {
            $this->assertDatabaseCount('content_versions', 1);
            $this->assertDatabaseCount('draft_submission_receipts', 0);
        } finally {
            DB::unprepared('DROP TRIGGER reject_draft_receipt');
        }
        $this->accept($agent, $input);
        $this->assertDatabaseCount('content_versions', 2);
    }

    public function test_rollback_refuses_to_destroy_machine_provenance(): void
    {
        [$agent, $item, $input] = $this->fixture();
        $this->accept($agent, $input);
        $migration = require database_path('migrations/2026_10_17_034000_create_draft_submission_receipts.php');
        try {
            $migration->down();
            $this->fail('Machine provenance discarded.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Machine-authored versions prevent rollback.', $error->getMessage());
            $this->assertDatabaseCount('draft_submission_receipts', 1);
            $this->assertDatabaseCount('content_versions', 2);
        }
    }

    public function test_authenticated_acceptance_uses_persisted_scope_and_replays_exactly(): void
    {
        [$agent, $item, $input] = $this->fixture();
        $token = $agent->createToken('test', ['draft:write'], now()->addHour())->accessToken;
        $agent->withAccessToken($token);
        // An in-memory scope is not authoritative.
        $token->abilities = ['*'];
        $service = app(AcceptDraftSubmission::class);
        $receipt = $service->acceptAuthenticated($agent, $input);
        $this->assertEquals($receipt, $service->acceptAuthenticated($agent, $input));
        $this->assertDatabaseCount('content_versions', 2);
        $this->assertDatabaseCount('draft_submission_receipts', 1);
    }

    public static function invalidCredentials(): array
    {
        return array_map(fn ($case) => [$case], [
            'missing', 'transient', 'revoked', 'expired', 'no_expiry', 'max_age',
            'changed_hash', 'foreign', 'scope', 'wildcard', 'disabled',
        ]);
    }

    #[DataProvider('invalidCredentials')]
    public function test_invalid_persisted_credentials_reject_fresh_input_and_committed_replay(string $case): void
    {
        [$agent, $item, $input] = $this->fixture();
        $token = $agent->createToken('test', ['draft:write'], now()->addHour())->accessToken;
        $agent->withAccessToken($token);
        $service = app(AcceptDraftSubmission::class);
        $service->acceptAuthenticated($agent, $input);
        switch ($case) {
            case 'missing': $agent->withAccessToken(null);
                break;
            case 'transient': $agent->withAccessToken(new TransientToken);
                break;
            case 'revoked': $token->delete();
                break;
            case 'expired': $token->newQuery()->whereKey($token->id)->update(['expires_at' => now()->subSecond()]);
                break;
            case 'no_expiry': $token->newQuery()->whereKey($token->id)->update(['expires_at' => null]);
                break;
            case 'max_age':
                config(['sanctum.expiration' => 5]);
                $token->newQuery()->whereKey($token->id)->update(['created_at' => now()->subMinutes(5)]);
                break;
            case 'changed_hash': $token->newQuery()->whereKey($token->id)->update(['token' => hash('sha256', 'replacement')]);
                break;
            case 'foreign':
                $other = AgentPrincipal::forceCreate(['name' => 'journalist', 'enabled' => true]);
                $agent->withAccessToken($other->createToken('other', ['research:write'], now()->addHour())->accessToken);
                break;
            case 'scope': $token->newQuery()->whereKey($token->id)->update(['abilities' => json_encode(['research:write'])]);
                break;
            case 'wildcard': $token->newQuery()->whereKey($token->id)->update(['abilities' => json_encode(['*'])]);
                break;
            case 'disabled': DB::table('agent_principals')->where('id', $agent->id)->update(['enabled' => false]);
                break;
        }
        foreach ([$input, array_replace($input, ['idempotency_key' => 'Fresh:2'])] as $request) {
            try {
                $service->acceptAuthenticated($agent, $request);
                $this->fail('Invalid credential accepted.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('content_versions', 2);
                $this->assertDatabaseCount('draft_submission_receipts', 1);
                $this->assertDatabaseCount('publications', 0);
            }
        }
    }
}
