<?php

namespace Tests\Feature;

use App\Services\Agents\CurlOpenBaoCredentialConsumer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SensitiveParameter;

class CurlOpenBaoCredentialConsumerTest extends TestCase
{
    public function test_unsafe_callback_is_rejected_before_host_authentication(): void
    {
        $calls = 0;
        $consumer = new CurlOpenBaoCredentialConsumer('https://127.0.0.1:1/v1/chub', 1, 1,
            function () use (&$calls) {
                $calls++;

                return 'synthetic';
            });
        try {
            $consumer->consume(function (string $bearer): void {});
            $this->fail('Unredacted callback accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame('Host credential callback must redact its input.', $error->getMessage());
            $this->assertSame(0, $calls);
        }
    }

    public function test_resolver_failure_is_masked_and_resolved_again_without_callback_or_fallback(): void
    {
        $resolutions = 0;
        $uses = 0;
        $consumer = new CurlOpenBaoCredentialConsumer('https://127.0.0.1:1/v1/chub', 1, 1,
            function () use (&$resolutions) {
                $resolutions++;
                throw new RuntimeException('synthetic-private-error');
            });
        for ($i = 0; $i < 2; $i++) {
            try {
                $consumer->consume(function (#[SensitiveParameter] string $bearer) use (&$uses): void {
                    $uses++;
                });
                $this->fail('Resolver failure accepted.');
            } catch (RuntimeException $error) {
                $this->assertSame('Host credential consumption failed.', $error->getMessage());
                $this->assertNull($error->getPrevious());
            }
        }
        $this->assertSame(2, $resolutions);
        $this->assertSame(0, $uses);
        $this->assertStringNotContainsString('127.0.0.1', print_r($consumer, true));
        $this->expectException(RuntimeException::class);
        serialize($consumer);
    }

    public function test_invalid_identity_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        new CurlOpenBaoCredentialConsumer('https://example.test/v1/chub', 0, 1, fn () => 'synthetic');
    }
}
