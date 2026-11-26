<?php

namespace Tests\Feature;

use App\Services\Agents\CurlOpenBaoTransport;
use App\Services\Agents\OpenBaoCredentialSink;
use App\Services\Agents\OpenBaoTransport;
use RuntimeException;
use SensitiveParameter;
use Tests\TestCase;

class OpenBaoCredentialSinkTest extends TestCase
{
    private function transport(): OpenBaoTransport
    {
        return new class implements OpenBaoTransport
        {
            public array $entries = [];

            public bool $race = false;

            public bool $fail = false;

            public function request(string $method, string $path, #[SensitiveParameter] array $payload = []): array
            {
                if ($this->fail) {
                    throw new RuntimeException('synthetic-private-provider-error');
                }
                $key = substr($path, strpos($path, '/') + 1);
                $entry = $this->entries[$key] ?? null;
                if ($method === 'GET') {
                    $result = ['status' => $entry ? 200 : 404, 'body' => ['data' => ['current_version' => $entry['version'] ?? 0]]];
                    if ($this->race) {
                        $this->race = false;
                        $this->entries[$key] = ['version' => 1, 'data' => ['state' => 'active', 'bearer' => 'synthetic-racing-value']];
                    }

                    return $result;
                }
                if ($payload['options']['cas'] !== ($entry['version'] ?? 0)) {
                    return ['status' => 400];
                }
                $this->entries[$key] = ['version' => ($entry['version'] ?? 0) + 1, 'data' => $payload['data']];

                return ['status' => 200];
            }
        };
    }

    public function test_store_and_revoke_fence_late_writes_and_preserve_other_credentials(): void
    {
        $transport = $this->transport();
        $sink = new OpenBaoCredentialSink($transport);
        $sink->store(1, 1, 'synthetic-first');
        $sink->store(1, 2, 'synthetic-replacement');
        $other = $transport->entries['chub/1/2'];
        $sink->revoke(1, 1);
        $sink->revoke(1, 1);
        try {
            $sink->store(1, 1, 'synthetic-late');
            $this->fail('Late store must fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('Host credential storage failed.', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame(['state' => 'revoked'], $transport->entries['chub/1/1']['data']);
        $this->assertSame($other, $transport->entries['chub/1/2']);
    }

    public function test_revocation_before_store_and_store_between_metadata_and_cas(): void
    {
        foreach ([false, true] as $race) {
            $transport = $this->transport();
            $transport->race = $race;
            $sink = new OpenBaoCredentialSink($transport);
            $sink->revoke(2, 3);
            $this->assertSame(['state' => 'revoked'], $transport->entries['chub/2/3']['data']);
            try {
                $sink->store(2, 3, 'synthetic-late');
                $this->fail('Tombstone must survive.');
            } catch (RuntimeException $e) {
                $this->assertSame('Host credential storage failed.', $e->getMessage());
            }
        }
    }

    public function test_provider_errors_are_not_chained_or_returned(): void
    {
        $transport = $this->transport();
        $transport->fail = true;
        $sink = new OpenBaoCredentialSink($transport);
        foreach (['store', 'revoke'] as $method) {
            try {
                $method === 'store' ? $sink->store(1, 1, 'synthetic-private') : $sink->revoke(1, 1);
                $this->fail('Provider failure must fail closed.');
            } catch (RuntimeException $e) {
                $this->assertStringNotContainsString('synthetic', $e->getMessage());
                $this->assertNull($e->getPrevious());
            }
        }
    }

    public function test_runtime_authentication_fails_closed_and_masks_provider_errors(): void
    {
        foreach ([null, '', "synthetic\r\ninjected", 'synthetic bad', 42] as $value) {
            $calls = 0;
            $transport = new CurlOpenBaoTransport('https://bao.example/v1/chub', function () use (&$calls, $value) {
                $calls++;

                return $value;
            });
            for ($i = 0; $i < 2; $i++) {
                try {
                    $transport->request('GET', 'metadata/chub/1/1');
                    $this->fail('Invalid runtime authentication must fail.');
                } catch (RuntimeException $e) {
                    $this->assertSame('Host secret-store request failed.', $e->getMessage());
                    $this->assertNull($e->getPrevious());
                }
            }
            $this->assertSame(2, $calls);
        }
        $transport = new CurlOpenBaoTransport('https://bao.example/v1/chub', function () {
            throw new RuntimeException('synthetic-private-provider-error');
        });
        try {
            $transport->request('GET', 'metadata/chub/1/1');
            $this->fail('Provider error must fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('Host secret-store request failed.', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function test_transport_rejects_missing_explicit_ca_bundle(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid host secret-store configuration.');
        new CurlOpenBaoTransport('https://bao.example/v1/chub', 'synthetic-host-auth', __DIR__.'/missing-ca.pem');
    }

    public function test_transport_masks_debug_and_refuses_serialization_and_secret_reads(): void
    {
        $transport = new CurlOpenBaoTransport('https://bao.example/v1/chub', 'synthetic-host-auth');
        ob_start();
        var_dump($transport);
        $this->assertStringNotContainsString('synthetic-host-auth', ob_get_clean());
        foreach ([fn () => serialize($transport), fn () => $transport->request('GET', 'data/chub/1/1')] as $operation) {
            try {
                $operation();
                $this->fail('Unsafe operation must fail.');
            } catch (RuntimeException $e) {
                $this->assertStringNotContainsString('synthetic-host-auth', $e->getMessage());
            }
        }
    }
}
