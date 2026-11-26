<?php

namespace Tests\Feature;

use App\Models\AgentPrincipal;
use App\Services\Agents\AgentCredentialSink;
use App\Services\Agents\DeliverAgentCredential;
use App\Services\Agents\RecoverAgentCredentialDelivery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Tests\TestCase;

class AgentCredentialDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    private function principal(): AgentPrincipal
    {
        $agent = new AgentPrincipal;
        $agent->forceFill(['name' => 'journalist', 'enabled' => true])->save();

        return $agent;
    }

    public function test_sink_receives_committed_credential_and_caller_only_receives_id(): void
    {
        $agent = $this->principal();
        $sink = new class implements AgentCredentialSink
        {
            public bool $verified = false;

            public function store(int $principalId, int $credentialId, #[\SensitiveParameter] string $bearer): void
            {
                $token = PersonalAccessToken::findToken($bearer);
                $this->verified = DB::transactionLevel() === 0
                    && $token?->getKey() === $credentialId
                    && (int) $token->tokenable_id === $principalId;
            }
        };
        $id = app(DeliverAgentCredential::class)->deliver($agent, $sink, 'host', ['research:write'], now()->addHour());
        $this->assertTrue($sink->verified);
        $this->assertDatabaseHas('agent_credential_deliveries', ['state' => 'delivered']);
        $this->assertIsInt($id);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_outer_transaction_is_rejected_before_issuance_or_delivery(): void
    {
        $agent = $this->principal();
        $sink = $this->failingSink();
        DB::beginTransaction();
        try {
            app(DeliverAgentCredential::class)->deliver($agent, $sink, 'host', ['research:write'], now()->addHour());
            $this->fail('Outer transaction admitted.');
        } catch (RuntimeException $error) {
            $this->assertSame('Credential delivery requires a committed host operation.', $error->getMessage());
            $this->assertDatabaseCount('personal_access_tokens', 0);
        } finally {
            DB::rollBack();
        }
    }

    public function test_failed_initial_handoff_revokes_and_redacts_sink_error(): void
    {
        $agent = $this->principal();
        $this->assertRevokedFailure($agent);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_failed_rotation_never_restores_old_token_or_deletes_unrelated_token(): void
    {
        $agent = $this->principal();
        $old = $agent->createToken('old', ['research:write'], now()->addHour());
        $unrelated = $agent->createToken('unrelated', ['research:write'], now()->addHour());
        $this->assertRevokedFailure($agent, $old->accessToken);
        $this->assertNull(PersonalAccessToken::findToken($old->plainTextToken));
        $this->assertNotNull(PersonalAccessToken::findToken($unrelated->plainTextToken));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_successful_rotation_revokes_old_before_delivery(): void
    {
        $agent = $this->principal();
        $old = $agent->createToken('old', ['research:write'], now()->addHour());
        $sink = new class((int) $old->accessToken->getKey()) implements AgentCredentialSink
        {
            public bool $verified = false;

            public function __construct(private int $oldId) {}

            public function store(int $principalId, int $credentialId, #[\SensitiveParameter] string $bearer): void
            {
                $this->verified = PersonalAccessToken::find($this->oldId) === null
                    && PersonalAccessToken::findToken($bearer)?->getKey() === $credentialId
                    && DB::transactionLevel() === 0;
            }
        };
        app(DeliverAgentCredential::class)->deliver($agent, $sink, 'host', ['research:write'], now()->addHour(), $old->accessToken);
        $this->assertTrue($sink->verified);
        $this->assertDatabaseHas('agent_credential_deliveries', ['state' => 'delivered']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_revocation_failure_reports_recovery_without_chaining_secret_exception(): void
    {
        $agent = $this->principal();
        DB::unprepared("CREATE TRIGGER fail_delivery_revoke BEFORE DELETE ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");
        try {
            app(DeliverAgentCredential::class)->deliver($agent, $this->failingSink(), 'host', ['research:write'], now()->addHour());
            $this->fail('Failed revocation hidden.');
        } catch (RuntimeException $error) {
            $this->assertSame('Credential delivery failed; revocation requires host recovery.', $error->getMessage());
            $this->assertNull($error->getPrevious());
            $this->assertDatabaseCount('personal_access_tokens', 1);
            $this->assertDatabaseHas('agent_credential_deliveries', ['state' => 'pending']);
        } finally {
            DB::unprepared('DROP TRIGGER fail_delivery_revoke');
        }
    }

    public function test_ledger_failure_rolls_back_rotation_and_never_calls_sink(): void
    {
        $agent = $this->principal();
        $old = $agent->createToken('old', ['research:write'], now()->addHour());
        $sink = new class implements AgentCredentialSink
        {
            public bool $called = false;

            public function store(int $principalId, int $credentialId, #[\SensitiveParameter] string $bearer): void
            {
                $this->called = true;
            }
        };
        DB::unprepared("CREATE TRIGGER fail_delivery_insert BEFORE INSERT ON agent_credential_deliveries BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");
        try {
            app(DeliverAgentCredential::class)->deliver($agent, $sink, 'host', ['research:write'], now()->addHour(), $old->accessToken);
            $this->fail('Missing ledger accepted.');
        } catch (QueryException) {
            $this->assertFalse($sink->called);
            $this->assertNotNull(PersonalAccessToken::findToken($old->plainTextToken));
            $this->assertDatabaseCount('personal_access_tokens', 1);
            $this->assertDatabaseCount('agent_credential_deliveries', 0);
        } finally {
            DB::unprepared('DROP TRIGGER fail_delivery_insert');
        }
    }

    public function test_recovery_revokes_only_expired_pending_delivery_and_is_idempotent(): void
    {
        $agent = $this->principal();
        $token = $agent->createToken('lost', ['research:write'], now()->addHour());
        $other = $agent->createToken('other', ['research:write'], now()->addHour());
        $id = DB::table('agent_credential_deliveries')->insertGetId([
            'agent_principal_id' => $agent->id, 'credential_id' => $token->accessToken->id,
            'state' => 'pending', 'recover_after' => now()->addMinutes(5),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(RecoverAgentCredentialDelivery::class);
        $this->assertFalse($service->recover($id));
        $this->travel(6)->minutes();
        $this->assertTrue($service->recover($id));
        $this->assertFalse($service->recover($id));
        $this->assertFalse($service->recover($id + 999));
        $this->assertNull(PersonalAccessToken::findToken($token->plainTextToken));
        $this->assertNotNull(PersonalAccessToken::findToken($other->plainTextToken));
        $this->assertDatabaseHas('agent_credential_deliveries', ['id' => $id, 'state' => 'revoked']);
    }

    public function test_recovery_does_not_revoke_acknowledged_delivery(): void
    {
        $agent = $this->principal();
        $sink = new class implements AgentCredentialSink
        {
            public function store(int $principalId, int $credentialId, #[\SensitiveParameter] string $bearer): void {}
        };
        $credential = app(DeliverAgentCredential::class)->deliver($agent, $sink, 'host', ['research:write'], now()->addHour());
        $delivery = DB::table('agent_credential_deliveries')->first();
        $this->travel(6)->minutes();
        $this->assertFalse(app(RecoverAgentCredentialDelivery::class)->recover($delivery->id));
        $this->assertNotNull(PersonalAccessToken::find($credential));
    }

    public function test_delayed_sink_acknowledgement_cannot_resurrect_recovered_credential(): void
    {
        $agent = $this->principal();
        $sink = new class implements AgentCredentialSink
        {
            public function store(int $principalId, int $credentialId, #[\SensitiveParameter] string $bearer): void
            {
                $delivery = DB::table('agent_credential_deliveries')->where('credential_id', $credentialId)->first();
                DB::table('agent_credential_deliveries')->where('id', $delivery->id)->update(['recover_after' => now()->subMinute()]);
                app(RecoverAgentCredentialDelivery::class)->recover($delivery->id);
            }
        };
        try {
            app(DeliverAgentCredential::class)->deliver($agent, $sink, 'host', ['research:write'], now()->addHour());
            $this->fail('Late acknowledgement accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame('Credential delivery failed; replacement revoked.', $error->getMessage());
            $this->assertDatabaseCount('personal_access_tokens', 0);
            $this->assertDatabaseHas('agent_credential_deliveries', ['state' => 'revoked']);
        }
    }

    public function test_unannotated_implementation_is_rejected_before_issuance_or_rotation(): void
    {
        $agent = $this->principal();
        $old = $agent->createToken('existing', ['research:write'], now()->addHour());
        $sink = new class implements AgentCredentialSink
        {
            public bool $called = false;

            // Deliberately unsafe: the interface attribute does not carry over.
            public function store(int $principalId, int $credentialId, string $bearer): void
            {
                $this->called = true;
            }
        };
        $before = PersonalAccessToken::find($old->accessToken->getKey())->getRawOriginal();
        foreach ([null, $old->accessToken] as $previous) {
            try {
                app(DeliverAgentCredential::class)->deliver($agent, $sink, 'host', ['research:write'], now()->addHour(), $previous);
                $this->fail('Unredacted sink admitted.');
            } catch (RuntimeException $error) {
                $this->assertSame('Credential sink requires sensitive bearer parameter redaction.', $error->getMessage());
                $this->assertNull($error->getPrevious());
            }
            $this->assertFalse($sink->called);
            $this->assertDatabaseCount('agent_credential_deliveries', 0);
            $this->assertDatabaseCount('personal_access_tokens', 1);
            $this->assertTrue($before === PersonalAccessToken::find($old->accessToken->getKey())->getRawOriginal());
        }
    }

    public function test_annotated_sink_redacts_actual_exception_trace_arguments(): void
    {
        $agent = $this->principal();
        $sink = new class implements AgentCredentialSink
        {
            public bool $redacted = false;

            public function store(int $principalId, int $credentialId, #[\SensitiveParameter] string $bearer): void
            {
                $error = new RuntimeException('Synthetic adapter failure.');
                $frame = $error->getTrace()[0];
                $this->redacted = $frame['args'][2] instanceof \SensitiveParameterValue
                    && ! str_contains($error->getTraceAsString(), $bearer);
                throw $error;
            }
        };
        $original = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                app(DeliverAgentCredential::class)->deliver($agent, $sink, 'host', ['research:write'], now()->addHour());
                $this->fail('Failed handoff accepted.');
            } catch (RuntimeException $error) {
                $this->assertSame('Credential delivery failed; replacement revoked.', $error->getMessage());
                $this->assertNull($error->getPrevious());
            }
            $this->assertTrue($sink->redacted);
            $this->assertDatabaseCount('personal_access_tokens', 0);
            $this->assertDatabaseHas('agent_credential_deliveries', ['state' => 'revoked']);
        } finally {
            ini_set('zend.exception_ignore_args', $original);
        }
    }

    private function failingSink(): AgentCredentialSink
    {
        return new class implements AgentCredentialSink
        {
            public function store(int $principalId, int $credentialId, #[\SensitiveParameter] string $bearer): void
            {
                // Simulates a host adapter accidentally including secret material.
                throw new RuntimeException($bearer);
            }
        };
    }

    private function assertRevokedFailure(AgentPrincipal $agent, ?PersonalAccessToken $previous = null): void
    {
        try {
            app(DeliverAgentCredential::class)->deliver($agent, $this->failingSink(), 'host', ['research:write'], now()->addHour(), $previous);
            $this->fail('Failed handoff accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame('Credential delivery failed; replacement revoked.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }
}
