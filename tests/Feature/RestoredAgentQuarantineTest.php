<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateResearchAgent;
use App\Models\AgentPrincipal;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class RestoredAgentQuarantineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    private function agent(): AgentPrincipal
    {
        $agent = new AgentPrincipal;
        $agent->forceFill(['name' => 'journalist', 'enabled' => true])->save();

        return $agent;
    }

    public function test_restored_bearer_is_revoked_even_after_reenable_and_history_is_preserved(): void
    {
        config(['agents.research_api_enabled' => false]);
        $agent = $this->agent();
        $issued = $agent->createToken('restored', ['research:write'], now()->addHour());
        $user = User::factory()->create();
        $userBefore = $user->fresh()->getAttributes();
        DB::table('agent_credential_deliveries')->insert([
            'agent_principal_id' => $agent->id, 'credential_id' => $issued->accessToken->id,
            'state' => 'delivered', 'recover_after' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $history = DB::table('agent_credential_deliveries')->get()->toJson();
        Route::post('/test-restored-agent', fn () => response()->noContent())->middleware(AuthenticateResearchAgent::class);
        $this->withToken($issued->plainTextToken)->postJson('/test-restored-agent')->assertNoContent();
        $this->artisan('agents:quarantine-restored --confirm-isolated')->assertSuccessful();
        $this->assertFalse($agent->fresh()->enabled);
        $this->assertNull(PersonalAccessToken::findToken($issued->plainTextToken));
        $this->assertSame($userBefore, $user->fresh()->getAttributes());
        $this->assertSame($history, DB::table('agent_credential_deliveries')->get()->toJson());
        $this->artisan('agents:quarantine-restored --confirm-isolated')->assertSuccessful();
        $agent->refresh()->forceFill(['enabled' => true])->save();
        Auth::forgetGuards();
        $this->withToken($issued->plainTextToken)->postJson('/test-restored-agent')->assertUnauthorized();
        $fresh = $agent->createToken('new', ['research:write'], now()->addHour());
        Auth::forgetGuards();
        $this->withToken($fresh->plainTextToken)->postJson('/test-restored-agent')->assertNoContent();
    }

    public function test_missing_isolation_or_enabled_api_refuses_without_changes(): void
    {
        $agent = $this->agent();
        $issued = $agent->createToken('restored', ['research:write'], now()->addHour());
        config(['agents.research_api_enabled' => false]);
        $this->artisan('agents:quarantine-restored')->assertFailed();
        foreach ([true, 'false', null] as $enabled) {
            config(['agents.research_api_enabled' => $enabled]);
            $this->artisan('agents:quarantine-restored --confirm-isolated')->assertFailed();
        }
        $this->assertTrue($agent->fresh()->enabled);
        $this->assertNotNull($issued->accessToken->fresh());
    }

    public function test_database_failure_rolls_back_disablement_and_token_deletion(): void
    {
        config(['agents.research_api_enabled' => false]);
        $agent = $this->agent();
        $issued = $agent->createToken('restored', ['research:write'], now()->addHour());
        DB::unprepared("CREATE TRIGGER reject_recovery_delete BEFORE DELETE ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        $this->artisan('agents:quarantine-restored --confirm-isolated')->assertFailed();
        $this->assertTrue($agent->fresh()->enabled);
        $this->assertNotNull($issued->accessToken->fresh());
        DB::unprepared('DROP TRIGGER reject_recovery_delete');
        $this->artisan('agents:quarantine-restored --confirm-isolated')->assertSuccessful();
    }
}
