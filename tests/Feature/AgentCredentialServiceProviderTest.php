<?php

namespace Tests\Feature;

use App\Providers\AgentCredentialServiceProvider;
use App\Services\Agents\AgentCredentialRevocationSink;
use App\Services\Agents\AgentCredentialSink;
use App\Services\Agents\OpenBaoCredentialSink;
use RuntimeException;
use Tests\TestCase;

class AgentCredentialServiceProviderTest extends TestCase
{
    private function registerStore(array $settings): void
    {
        config(['agents.credential_store' => $settings]);
        (new AgentCredentialServiceProvider($this->app))->register();
    }

    public function test_default_and_non_boolean_enablement_do_not_bind_or_enable_api(): void
    {
        $this->assertFalse($this->app->bound(AgentCredentialSink::class));
        foreach ([false, null, 'false', 'true', 1] as $enabled) {
            $this->registerStore(['enabled' => $enabled]);
            $this->assertFalse($this->app->bound(AgentCredentialRevocationSink::class));
            $this->assertFalse($this->app->bound(AgentCredentialSink::class));
        }
        $this->assertFalse(config('agents.research_api_enabled'));
    }

    public function test_opt_in_shares_writer_revoker_without_reading_or_caching_token(): void
    {
        $this->registerStore([
            'enabled' => true, 'mount_url' => 'https://127.0.0.1:1/v1/chub',
            'token_file' => '/nonexistent/chub-host/token', 'ca_bundle' => null,
        ]);
        $sink = $this->app->make(AgentCredentialSink::class);
        $this->assertInstanceOf(OpenBaoCredentialSink::class, $sink);
        $this->assertSame($sink, $this->app->make(AgentCredentialRevocationSink::class));
        // Missing host authentication fails before network; no cached/static fallback.
        try {
            $sink->store(1, 1, 'synthetic-application-bearer');
            $this->fail('Unavailable host authentication accepted.');
        } catch (RuntimeException $e) {
            $this->assertSame('Host credential storage failed.', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $this->assertFalse(config('agents.research_api_enabled'));
    }

    public function test_invalid_configuration_has_fixed_unchained_error(): void
    {
        foreach ([
            [],
            ['mount_url' => 'http://localhost/v1/chub', 'token_file' => '/private/token'],
            ['mount_url' => 'https://localhost/v1/chub', 'token_file' => 'relative/token'],
            ['mount_url' => 'https://localhost/v1/chub', 'token_file' => '/private/token', 'ca_bundle' => []],
        ] as $settings) {
            $this->registerStore(['enabled' => true] + $settings);
            try {
                $this->app->make(AgentCredentialSink::class);
                $this->fail('Invalid configuration accepted.');
            } catch (RuntimeException $e) {
                $this->assertSame('Host credential store configuration unavailable.', $e->getMessage());
                $this->assertNull($e->getPrevious());
            }
        }
    }
}
