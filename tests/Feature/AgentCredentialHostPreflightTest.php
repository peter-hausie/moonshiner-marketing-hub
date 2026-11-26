<?php

namespace Tests\Feature;

use App\Providers\AgentCredentialServiceProvider;
use App\Services\Agents\AgentCredentialRevocationSink;
use App\Services\Agents\AgentCredentialSink;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentCredentialHostPreflightTest extends TestCase
{
    public function test_disabled_or_ambiguous_opt_in_fails_closed(): void
    {
        foreach ([false, null, 'true', 1] as $enabled) {
            config(['agents.credential_store.enabled' => $enabled]);
            $this->artisan('agents:check-credential-host')
                ->expectsOutput('Credential host preflight unavailable: store is not explicitly enabled.')
                ->assertFailed();
        }
    }

    public function test_private_sink_passes_locally_but_permissions_and_missing_file_fail(): void
    {
        Http::preventStrayRequests();
        $directory = sys_get_temp_dir().'/chub-preflight-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $directory = realpath($directory);
        $path = $directory.'/token';
        file_put_contents($path, 'synthetic-host-token');
        chmod($path, 0600);
        config(['agents.credential_store' => [
            'enabled' => true, 'mount_url' => 'https://127.0.0.1:1/v1/chub',
            'token_file' => $path, 'ca_bundle' => null,
        ]]);
        (new AgentCredentialServiceProvider($this->app))->register();
        try {
            // Unreachable endpoint deliberately proves this is local-only.
            $this->artisan('agents:check-credential-host')
                ->expectsOutput('Local credential host preflight passed. Remote authentication, ACL, renewal and audit capacity remain unverified.')
                ->assertSuccessful();
            chmod($path, 0644);
            $this->assertLocalFailure();
            chmod($path, 0600);
            unlink($path);
            $this->assertLocalFailure();
            $this->assertFalse(config('agents.research_api_enabled'));
            Http::assertNothingSent();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function test_invalid_store_configuration_is_redacted(): void
    {
        config(['agents.credential_store' => [
            'enabled' => true, 'mount_url' => 'http://invalid.example',
            'token_file' => '/nonexistent/private/token',
        ]]);
        (new AgentCredentialServiceProvider($this->app))->register();
        $this->assertLocalFailure();
    }

    public function test_preparation_validates_disabled_store_without_binding_delivery_or_enabling_apis(): void
    {
        Http::preventStrayRequests();
        $directory = sys_get_temp_dir().'/chub-prepare-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $directory = realpath($directory);
        $path = $directory.'/token';
        file_put_contents($path, 'synthetic-host-token');
        chmod($path, 0600);
        config(['agents.credential_store' => [
            'enabled' => false, 'mount_url' => 'https://127.0.0.1:1/v1/chub',
            'token_file' => $path, 'ca_bundle' => null,
        ]]);
        $before = config('agents');
        try {
            $this->artisan('agents:check-credential-host --prepare')
                ->expectsOutput('Local credential host preflight passed. Remote authentication, ACL, renewal and audit capacity remain unverified.')
                ->assertSuccessful();
            $this->assertSame($before, config('agents'));
            $this->assertFalse($this->app->bound(AgentCredentialSink::class));
            $this->assertFalse($this->app->bound(AgentCredentialRevocationSink::class));
            $this->assertFalse(config('agents.research_api_enabled'));
            $this->assertFalse(config('agents.draft_api_enabled'));
            chmod($path, 0644);
            $this->artisan('agents:check-credential-host --prepare')
                ->expectsOutput('Credential host preflight failed: check runtime, configuration and private Agent sink.')
                ->assertFailed();
            chmod($path, 0600);
            config(['agents.credential_store.mount_url' => 'http://invalid.example']);
            $this->artisan('agents:check-credential-host --prepare')
                ->expectsOutput('Credential host preflight failed: check runtime, configuration and private Agent sink.')
                ->assertFailed();
            Http::assertNothingSent();
        } finally {
            unlink($path);
            rmdir($directory);
        }
    }

    public function test_preparation_rejects_live_and_ambiguous_store_flags(): void
    {
        foreach ([true, null, 'false', 'true', 0, 1] as $enabled) {
            config(['agents.credential_store.enabled' => $enabled]);
            $this->artisan('agents:check-credential-host --prepare')
                ->expectsOutput('Credential host preparation requires an explicitly disabled store.')
                ->assertFailed();
            $this->assertSame($enabled, config('agents.credential_store.enabled'));
        }
    }

    private function assertLocalFailure(): void
    {
        $this->artisan('agents:check-credential-host')
            ->expectsOutput('Credential host preflight failed: check runtime, configuration and private Agent sink.')
            ->assertFailed();
    }
}
