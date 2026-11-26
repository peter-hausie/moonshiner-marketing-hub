<?php

namespace Tests\Feature;

use App\Models\AgentPrincipal;
use App\Services\Agents\AgentCredentialRevocationSink;
use App\Services\Agents\ReconcileAgentCredentialBatch;
use App\Services\Agents\ReconcileAgentCredentialDelivery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AgentCredentialReconciliationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    private function delivery(string $state, bool $due = true): array
    {
        $agent = AgentPrincipal::query()->where('name', 'journalist')->first();
        if (! $agent) {
            $agent = new AgentPrincipal;
            $agent->forceFill(['name' => 'journalist', 'enabled' => true])->save();
        }
        $token = $agent->createToken('host', ['research:write'], now()->addHour());
        $credential = (int) $token->accessToken->getKey();
        $id = DB::table('agent_credential_deliveries')->insertGetId([
            'agent_principal_id' => $agent->getKey(), 'credential_id' => $credential,
            'state' => $state, 'recover_after' => $due ? now()->subMinute() : now()->addMinute(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($state === 'revoked') {
            $token->accessToken->delete();
        }

        return [$id, (int) $agent->getKey(), $credential];
    }

    private function sink(): AgentCredentialRevocationSink
    {
        return new class implements AgentCredentialRevocationSink
        {
            public array $revoked = [];

            public bool $fail = false;

            public function store(int $principalId, int $credentialId, #[\SensitiveParameter] string $bearer): void
            {
                if (isset($this->revoked[$principalId.':'.$credentialId])) {
                    throw new RuntimeException('Tombstoned credential.');
                }
            }

            public function revoke(int $principalId, int $credentialId): void
            {
                if (DB::transactionLevel() !== 0 || DB::table('personal_access_tokens')->where('id', $credentialId)->exists()) {
                    throw new RuntimeException('Revocation not committed.');
                }
                $this->revoked[$principalId.':'.$credentialId] = true;
                if ($this->fail) {
                    throw new RuntimeException('Synthetic private backend detail.');
                }
            }
        };
    }

    public function test_cli_pages_real_receipts_and_retries_redacted_sink_failure(): void
    {
        config(['agents.credential_store.enabled' => true]);
        [$first] = $this->delivery('pending');
        [$second] = $this->delivery('delivered');
        $sink = $this->sink();
        $this->app->instance(AgentCredentialRevocationSink::class, $sink);
        $sink->fail = true;
        $this->assertSame(1, Artisan::call('agents:reconcile-credentials', ['--limit' => '1']));
        $output = Artisan::output();
        $this->assertStringContainsString('"failed":1', $output);
        $this->assertStringContainsString('"next_after":'.$first, $output);
        $this->assertStringNotContainsString('Synthetic private', $output);
        $this->assertDatabaseHas('agent_credential_deliveries', ['id' => $first, 'state' => 'revoked']);
        $sink->fail = false;
        $this->assertSame(0, Artisan::call('agents:reconcile-credentials', ['--limit' => '1']));
        $this->assertStringContainsString('"reconciled":1', Artisan::output());
        $this->assertSame(0, Artisan::call('agents:reconcile-credentials', ['--after' => (string) $first, '--limit' => '1']));
        $this->assertStringContainsString('"unchanged":1,"failed":0,"next_after":null', Artisan::output());
        $this->assertDatabaseHas('agent_credential_deliveries', ['id' => $second, 'state' => 'delivered']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertFalse(config('agents.research_api_enabled'));
        $this->assertFalse(config('agents.draft_api_enabled'));
    }

    public function test_cli_rejects_disabled_invalid_and_unavailable_hosts_without_leaking_details(): void
    {
        [$id] = $this->delivery('pending');
        $before = DB::table('agent_credential_deliveries')->get()->toJson();
        foreach ([false, null, 'true', 1] as $enabled) {
            config(['agents.credential_store.enabled' => $enabled]);
            $this->assertSame(1, Artisan::call('agents:reconcile-credentials'));
        }
        config(['agents.credential_store.enabled' => true]);
        foreach ([['--after' => '-1'], ['--after' => '1e2'], ['--after' => '999999999999999999999'],
            ['--limit' => '0'], ['--limit' => '101'], ['--limit' => '1.5'], ['--limit' => '01']] as $options) {
            $this->assertSame(2, Artisan::call('agents:reconcile-credentials', $options));
        }
        $this->app->bind(AgentCredentialRevocationSink::class, function () {
            throw new RuntimeException('Synthetic private configuration');
        });
        $this->assertSame(1, Artisan::call('agents:reconcile-credentials'));
        $this->assertStringNotContainsString('Synthetic private', Artisan::output());
        $this->assertSame($before, DB::table('agent_credential_deliveries')->get()->toJson());
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_due_pending_recovers_before_sink_io_and_replays_preserve_unrelated_history(): void
    {
        [$id, $principal, $credential] = $this->delivery('pending');
        [$other] = $this->delivery('delivered');
        $unrelated = DB::table('agent_credential_deliveries')->find($other);
        $sink = $this->sink();
        $service = app(ReconcileAgentCredentialDelivery::class);
        $this->assertTrue($service->reconcile($id, $sink));
        $snapshot = DB::table('agent_credential_deliveries')->get()->toJson();
        $this->assertTrue($service->reconcile($id, $sink));
        $this->assertSame($snapshot, DB::table('agent_credential_deliveries')->get()->toJson());
        $this->assertEquals($unrelated, DB::table('agent_credential_deliveries')->find($other));
        $this->assertSame([$principal.':'.$credential => true], $sink->revoked);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->expectExceptionMessage('Tombstoned credential.');
        $sink->store($principal, $credential, 'synthetic-late-store');
    }

    public function test_acknowledged_future_and_missing_deliveries_do_not_touch_sink(): void
    {
        [$delivered] = $this->delivery('delivered');
        [$future] = $this->delivery('pending', false);
        $sink = $this->sink();
        foreach ([$delivered, $future, 99999] as $id) {
            $this->assertFalse(app(ReconcileAgentCredentialDelivery::class)->reconcile($id, $sink));
        }
        $this->assertSame([], $sink->revoked);
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public function test_ambiguous_sink_failure_is_redacted_and_retryable_without_token_resurrection(): void
    {
        [$id] = $this->delivery('pending');
        $sink = $this->sink();
        $sink->fail = true;
        try {
            app(ReconcileAgentCredentialDelivery::class)->reconcile($id, $sink);
            $this->fail('Sink failure not reported.');
        } catch (RuntimeException $error) {
            $this->assertSame('Credential reconciliation failed; host retry required.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        $this->assertDatabaseHas('agent_credential_deliveries', ['id' => $id, 'state' => 'revoked']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $sink->fail = false;
        $this->assertTrue(app(ReconcileAgentCredentialDelivery::class)->reconcile($id, $sink));
    }

    public function test_already_revoked_delivery_can_be_cleaned_without_recovery_mutation(): void
    {
        [$id] = $this->delivery('revoked');
        $before = DB::table('agent_credential_deliveries')->get()->toJson();
        $this->assertTrue(app(ReconcileAgentCredentialDelivery::class)->reconcile($id, $this->sink()));
        $this->assertSame($before, DB::table('agent_credential_deliveries')->get()->toJson());
    }

    public function test_rotated_delivered_credential_is_retired_without_touching_replacement(): void
    {
        [$id, $principal, $credential] = $this->delivery('delivered');
        $agent = AgentPrincipal::findOrFail($principal);
        $replacement = $agent->rotateToken($agent->tokens()->findOrFail($credential), 'replacement', ['research:write'], now()->addHour());
        $before = DB::table('personal_access_tokens')->get()->toJson();
        $sink = $this->sink();
        $this->assertTrue(app(ReconcileAgentCredentialDelivery::class)->reconcile($id, $sink));
        $this->assertSame($before, DB::table('personal_access_tokens')->get()->toJson());
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $replacement->accessToken->getKey()]);
        $this->assertSame([$principal.':'.$credential => true], $sink->revoked);
        $snapshot = DB::table('agent_credential_deliveries')->get()->toJson();
        $this->assertTrue(app(ReconcileAgentCredentialDelivery::class)->reconcile($id, $sink));
        $this->assertSame($snapshot, DB::table('agent_credential_deliveries')->get()->toJson());
    }

    public function test_expired_delivered_credential_is_revoked_before_retryable_sink_failure(): void
    {
        $this->freezeTime();
        [$id, $principal, $credential] = $this->delivery('delivered');
        DB::table('personal_access_tokens')->where('id', $credential)->update(['expires_at' => now()]);
        $sink = $this->sink();
        $sink->fail = true;
        try {
            app(ReconcileAgentCredentialDelivery::class)->reconcile($id, $sink);
            $this->fail('Sink failure not reported.');
        } catch (RuntimeException $error) {
            $this->assertSame('Credential reconciliation failed; host retry required.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $credential]);
        $this->assertDatabaseHas('agent_credential_deliveries', ['id' => $id, 'state' => 'revoked']);
        $sink->fail = false;
        $this->assertTrue(app(ReconcileAgentCredentialDelivery::class)->reconcile($id, $sink));
        $this->assertSame([$principal.':'.$credential => true], $sink->revoked);
    }

    public function test_disabled_principal_and_unbounded_token_are_not_implicitly_retired(): void
    {
        [$id, $principal, $credential] = $this->delivery('delivered');
        AgentPrincipal::whereKey($principal)->update(['enabled' => false]);
        $sink = $this->sink();
        $this->assertFalse(app(ReconcileAgentCredentialDelivery::class)->reconcile($id, $sink));
        DB::table('personal_access_tokens')->where('id', $credential)->update(['expires_at' => null]);
        $this->assertFalse(app(ReconcileAgentCredentialDelivery::class)->reconcile($id, $sink));
        $this->assertSame([], $sink->revoked);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $credential]);
        $this->assertDatabaseHas('agent_credential_deliveries', ['id' => $id, 'state' => 'delivered']);
    }

    public function test_batch_paginates_without_starvation_and_revisits_expired_entries(): void
    {
        $this->freezeTime();
        [$live, , $token] = $this->delivery('delivered');
        [$due] = $this->delivery('pending');
        [$revoked] = $this->delivery('revoked');
        $sink = $this->sink();
        $service = app(ReconcileAgentCredentialBatch::class);
        $first = $service->reconcile($sink, 0, 2);
        $this->assertSame(['next_after' => $due, 'reconciled' => [$due], 'unchanged' => [$live], 'failed' => []], $first);
        $second = $service->reconcile($sink, $first['next_after'], 2);
        $this->assertSame(['next_after' => null, 'reconciled' => [$revoked], 'unchanged' => [], 'failed' => []], $second);
        DB::table('personal_access_tokens')->where('id', $token)->update(['expires_at' => now()]);
        $again = $service->reconcile($sink);
        $this->assertSame([$live, $due, $revoked], $again['reconciled']);
        $this->assertSame([], $again['failed']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_batch_continues_after_ambiguous_failure_and_retries_next_sweep(): void
    {
        [$first] = $this->delivery('pending');
        [$second] = $this->delivery('pending');
        $sink = new class implements AgentCredentialRevocationSink
        {
            public int $calls = 0;

            public function store(int $principalId, int $credentialId, #[\SensitiveParameter] string $bearer): void {}

            public function revoke(int $principalId, int $credentialId): void
            {
                if (++$this->calls === 1) {
                    throw new RuntimeException('Synthetic private backend detail.');
                }
            }
        };
        $service = app(ReconcileAgentCredentialBatch::class);
        $this->assertSame(['next_after' => null, 'reconciled' => [$second], 'unchanged' => [], 'failed' => [$first]], $service->reconcile($sink));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $snapshot = DB::table('agent_credential_deliveries')->get()->toJson();
        $this->assertSame([$first, $second], $service->reconcile($sink)['reconciled']);
        $this->assertSame($snapshot, DB::table('agent_credential_deliveries')->get()->toJson());
        $this->assertSame(4, $sink->calls);
    }

    public function test_batch_rejects_invalid_bounds_and_outer_transaction_before_mutations(): void
    {
        [$id] = $this->delivery('pending');
        $sink = $this->sink();
        $service = app(ReconcileAgentCredentialBatch::class);
        foreach ([[-1, 1], [0, 0], [0, 101]] as [$after, $limit]) {
            try {
                $service->reconcile($sink, $after, $limit);
                $this->fail('Invalid page accepted.');
            } catch (\InvalidArgumentException $error) {
                $this->assertSame('Invalid credential reconciliation page.', $error->getMessage());
            }
        }
        DB::beginTransaction();
        try {
            $service->reconcile($sink);
            $this->fail('Outer transaction accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame('Credential reconciliation requires a committed host operation.', $error->getMessage());
        } finally {
            DB::rollBack();
        }
        $this->assertSame([], $sink->revoked);
        $this->assertDatabaseHas('agent_credential_deliveries', ['id' => $id, 'state' => 'pending']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertSame(['next_after' => null, 'reconciled' => [], 'unchanged' => [], 'failed' => []], $service->reconcile($sink, $id));
    }

    public function test_outer_transaction_rejected_before_recovery_or_sink_io(): void
    {
        [$id] = $this->delivery('pending');
        $sink = $this->sink();
        DB::beginTransaction();
        try {
            app(ReconcileAgentCredentialDelivery::class)->reconcile($id, $sink);
            $this->fail('Outer transaction admitted.');
        } catch (RuntimeException $error) {
            $this->assertSame('Credential reconciliation requires a committed host operation.', $error->getMessage());
            $this->assertSame([], $sink->revoked);
            $this->assertDatabaseHas('agent_credential_deliveries', ['id' => $id, 'state' => 'pending']);
            $this->assertDatabaseCount('personal_access_tokens', 1);
        } finally {
            DB::rollBack();
        }
    }
}
