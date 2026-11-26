<?php

namespace App\Services\Agents;

use Closure;
use ReflectionFunction;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Host-only exact-key consumer; never bound into the application container. */
final class CurlOpenBaoCredentialConsumer
{
    public function __construct(
        private string $mountUrl,
        private int $principalId,
        private int $credentialId,
        #[SensitiveParameter] private Closure $hostToken,
        private ?string $caBundle = null,
    ) {
        // Reuse the writer's configuration validation, but never its write capability.
        new CurlOpenBaoTransport($mountUrl, $hostToken, $caBundle);
        if ($principalId < 1 || $credentialId < 1) {
            throw new RuntimeException('Invalid host credential identity.');
        }
    }

    public function __debugInfo(): array
    {
        return ['consumer' => 'masked exact-key host credential'];
    }

    public function __serialize(): array
    {
        throw new RuntimeException('Host credential consumer cannot be serialized.');
    }

    /** Invoke trusted host code once, without returning or retaining the bearer. */
    public function consume(Closure $use): void
    {
        $parameter = (new ReflectionFunction($use))->getParameters()[0] ?? null;
        if ($parameter === null || $parameter->getAttributes(SensitiveParameter::class) === []) {
            throw new RuntimeException('Host credential callback must redact its input.');
        }
        $handle = curl_init(rtrim($this->mountUrl, '/').'/data/chub/'.$this->principalId.'/'.$this->credentialId);
        try {
            $token = ($this->hostToken)();
            if (! is_string($token) || $token === '' || preg_match('/[\x00-\x20\x7f]/', $token)) {
                throw new RuntimeException;
            }
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => ['X-Vault-Token: '.$token],
            ]);
            if ($this->caBundle !== null) {
                curl_setopt($handle, CURLOPT_CAINFO, $this->caBundle);
            }
            $response = curl_exec($handle);
            if (! is_string($response) || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new RuntimeException;
            }
            $data = json_decode($response, true, 32, JSON_THROW_ON_ERROR)['data']['data'] ?? null;
            if (! is_array($data) || count($data) !== 2 || ($data['state'] ?? null) !== 'active'
                || ! is_string($data['bearer'] ?? null) || $data['bearer'] === ''
                || strlen($data['bearer']) > 8192 || preg_match('/[\x00-\x20\x7f]/', $data['bearer'])) {
                throw new RuntimeException;
            }
            $use($data['bearer']);
        } catch (Throwable) {
            // No fallback/retry: callback side effects may already have happened.
            throw new RuntimeException('Host credential consumption failed.');
        } finally {
            unset($token, $response, $data);
            curl_close($handle);
        }
    }
}
