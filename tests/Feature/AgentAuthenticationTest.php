<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateResearchAgent;
use App\Models\AgentPrincipal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AgentAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Test-only adapter; no real API or token provisioning endpoint exists.
        Route::post('/api/test-research-auth', function (Request $request) {
            return response()->json(['agent' => $request->attributes->get('research_agent')->name]);
        })->middleware(AuthenticateResearchAgent::class);
    }

    private function principal(string $name = 'journalist'): AgentPrincipal
    {
        $agent = new AgentPrincipal;
        $agent->forceFill(['name' => $name, 'enabled' => true])->save();

        return $agent;
    }

    public function test_bearer_authentication_uses_server_identity_and_stores_only_hash(): void
    {
        $agent = $this->principal();
        $issued = $agent->createToken('test', ['research:write'], now()->addHour());
        $this->assertTrue(hash_equals(hash('sha256', explode('|', $issued->plainTextToken, 2)[1]), $issued->accessToken->token));
        $this->assertArrayNotHasKey('token', $issued->accessToken->toArray());
        $this->withToken($issued->plainTextToken)->postJson('/api/test-research-auth', ['agent' => 'underwood'])
            ->assertOk()->assertExactJson(['agent' => 'journalist']);
        $this->assertNotNull($issued->accessToken->fresh()->last_used_at);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_browser_user_missing_and_invalid_bearers_cannot_authenticate(): void
    {
        $this->postJson('/api/test-research-auth')->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/test-research-auth')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken('invalid-test-input')->postJson('/api/test-research-auth')->assertUnauthorized();
    }

    public function test_disabled_expired_deleted_and_underwood_credentials_are_rejected(): void
    {
        $agent = $this->principal();
        $issued = $agent->createToken('test', ['research:write'], now()->addHour());
        $agent->forceFill(['enabled' => false])->save();
        $this->withToken($issued->plainTextToken)->postJson('/api/test-research-auth')->assertForbidden();
        $agent->forceFill(['enabled' => true])->save();
        $issued->accessToken->forceFill(['expires_at' => now()->subSecond()])->save();
        Auth::forgetGuards();
        $this->postJson('/api/test-research-auth')->assertUnauthorized();
        $issued->accessToken->delete();
        Auth::forgetGuards();
        $this->postJson('/api/test-research-auth')->assertUnauthorized();
        $underwood = $this->principal('underwood')->createToken('test', ['draft:write'], now()->addHour());
        Auth::forgetGuards();
        $this->withToken($underwood->plainTextToken)->postJson('/api/test-research-auth')->assertForbidden();
    }

    public function test_issuance_forbids_wildcards_cross_agent_privileges_and_unbounded_lifetime(): void
    {
        $agent = $this->principal();
        foreach ([['*'], ['draft:write'], ['approval:write'], ['credentials:read'], []] as $abilities) {
            try {
                $agent->createToken('test', $abilities, now()->addHour());
                $this->fail('Unsafe abilities accepted.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('personal_access_tokens', 0);
            }
        }
        foreach ([null, now()->subSecond(), now()->addDays(2)] as $expiry) {
            try {
                $agent->createToken('test', ['research:write'], $expiry);
                $this->fail('Unsafe lifetime accepted.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('personal_access_tokens', 0);
            }
        }
    }

    public function test_issuance_reloads_disabled_renamed_and_deleted_principals(): void
    {
        $agent = $this->principal();
        foreach ([['enabled' => false], ['enabled' => true, 'name' => 'underwood']] as $mutation) {
            AgentPrincipal::query()->whereKey($agent->id)->update($mutation);
            try {
                $agent->createToken('test', ['research:write'], now()->addHour());
                $this->fail('Stale principal admitted issuance.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('personal_access_tokens', 0);
            }
        }
        AgentPrincipal::query()->whereKey($agent->id)->delete();
        $this->expectException(AuthorizationException::class);
        $agent->createToken('test', ['research:write'], now()->addHour());
    }

    public function test_rotation_revokes_only_the_replaced_bearer_and_new_bearer_authenticates(): void
    {
        $agent = $this->principal();
        $old = $agent->createToken('old', ['research:write'], now()->addHour());
        $other = $agent->createToken('other', ['research:write'], now()->addHour());
        $before = $other->accessToken->fresh()->getRawOriginal();
        $replacement = $agent->rotateToken($old->accessToken, 'replacement', ['research:write'], now()->addHour());
        $this->assertNull($old->accessToken->fresh());
        $this->assertSame($before, $other->accessToken->fresh()->getRawOriginal());
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->withToken($old->plainTextToken)->postJson('/api/test-research-auth')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($replacement->plainTextToken)->postJson('/api/test-research-auth')->assertOk();
        $this->assertArrayNotHasKey('token', $replacement->accessToken->toArray());
        try {
            $agent->rotateToken($old->accessToken, 'again', ['research:write'], now()->addHour());
            $this->fail('Revoked credential was rotated again.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('personal_access_tokens', 2);
        }
    }

    public function test_rotation_rejects_foreign_credentials_scope_changes_and_disabled_principal(): void
    {
        $agent = $this->principal();
        $old = $agent->createToken('old', ['research:write'], now()->addHour());
        $foreign = $this->principal('underwood')->createToken('foreign', ['draft:write'], now()->addHour());
        foreach ([[$foreign->accessToken, ['research:write']], [$old->accessToken, ['*']], [$old->accessToken, ['draft:write']], [$old->accessToken, []]] as [$token, $abilities]) {
            $before = DB::table('personal_access_tokens')->orderBy('id')->get()->toJson();
            try {
                $agent->rotateToken($token, 'invalid', $abilities, now()->addHour());
                $this->fail('Unsafe rotation accepted.');
            } catch (AuthorizationException) {
                $this->assertSame($before, DB::table('personal_access_tokens')->orderBy('id')->get()->toJson());
            }
        }
        AgentPrincipal::query()->whereKey($agent->id)->update(['enabled' => false]);
        try {
            $agent->rotateToken($old->accessToken, 'invalid', ['research:write'], now()->addHour());
            $this->fail('Disabled principal rotated a credential.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('personal_access_tokens', 2);
            $this->assertNotNull($old->accessToken->fresh());
        }
    }

    public function test_rotation_rechecks_current_token_digest_and_scopes(): void
    {
        $agent = $this->principal();
        $old = $agent->createToken('old', ['research:write'], now()->addHour());
        foreach ([['abilities' => []], ['abilities' => ['*']], ['abilities' => ['research:write'], 'token' => str_repeat('0', 64)]] as $mutation) {
            $old->accessToken->fresh()->forceFill($mutation)->save();
            $before = DB::table('personal_access_tokens')->get()->toJson();
            try {
                $agent->rotateToken($old->accessToken, 'invalid', ['research:write'], now()->addHour());
                $this->fail('Changed credential rotated through stale model.');
            } catch (AuthorizationException) {
                $this->assertSame($before, DB::table('personal_access_tokens')->get()->toJson());
            }
        }
    }

    public function test_failed_revocation_rolls_back_replacement_and_preserves_old_credential(): void
    {
        $agent = $this->principal();
        $old = $agent->createToken('old', ['research:write'], now()->addHour());
        $before = DB::table('personal_access_tokens')->get()->toJson();
        DB::unprepared("CREATE TRIGGER reject_token_delete BEFORE DELETE ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'forced revocation failure'); END");
        try {
            $agent->rotateToken($old->accessToken, 'replacement', ['research:write'], now()->addHour());
            $this->fail('Forced revocation failure did not occur.');
        } catch (QueryException) {
            $this->assertSame($before, DB::table('personal_access_tokens')->get()->toJson());
        } finally {
            DB::unprepared('DROP TRIGGER reject_token_delete');
        }
        $this->withToken($old->plainTextToken)->postJson('/api/test-research-auth')->assertOk();
        $replacement = $agent->rotateToken($old->accessToken, 'retry', ['research:write'], now()->addHour());
        $this->assertNotNull($replacement->accessToken->fresh());
        $this->assertNull($old->accessToken->fresh());
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_stored_wildcard_missing_expiry_wrong_model_and_orphan_fail_closed(): void
    {
        $agent = $this->principal();
        $issued = $agent->createToken('test', ['research:write'], now()->addHour());
        $this->withToken($issued->plainTextToken);
        foreach ([['abilities' => ['*']], ['abilities' => ['research:write', 'approval:write']], ['abilities' => []], ['abilities' => ['research:write'], 'expires_at' => null]] as $mutation) {
            $issued->accessToken->forceFill($mutation)->save();
            Auth::forgetGuards();
            $this->postJson('/api/test-research-auth')->assertForbidden();
        }
        $user = User::factory()->create();
        $issued->accessToken->forceFill(['expires_at' => now()->addHour(), 'tokenable_type' => User::class, 'tokenable_id' => $user->id])->save();
        Auth::forgetGuards();
        $this->postJson('/api/test-research-auth')->assertUnauthorized();
        $issued->accessToken->forceFill(['tokenable_type' => AgentPrincipal::class, 'tokenable_id' => $agent->id])->save();
        $agent->delete();
        Auth::forgetGuards();
        $this->postJson('/api/test-research-auth')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 1); // Orphan remains unusable.
    }
}
