<?php

namespace App\Services\Agents;

use Closure;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Dedicated transport avoids application HTTP events/debug middleware carrying secrets. */
class CurlOpenBaoTransport implements OpenBaoTransport
{
    public function __construct(private string $mountUrl, #[SensitiveParameter] private string|Closure $hostToken, private ?string $caBundle = null)
    {
        $url = parse_url($mountUrl);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['query']) || isset($url['fragment'])
            || ($caBundle !== null && (! is_file($caBundle) || ! is_readable($caBundle)))
            || (is_string($hostToken) && ! $this->validToken($hostToken))) {
            throw new RuntimeException('Invalid host secret-store configuration.');
        }
    }

    private function validToken(#[SensitiveParameter] mixed $token): bool
    {
        return is_string($token) && $token !== '' && ! preg_match('/[\x00-\x20\x7f]/', $token);
    }

    public function __debugInfo(): array
    {
        return ['transport' => 'masked host secret store'];
    }

    public function __serialize(): array
    {
        throw new RuntimeException('Host secret transport cannot be serialized.');
    }

    public function request(string $method, string $path, #[SensitiveParameter] array $payload = []): array
    {
        if (! (($method === 'GET' && preg_match('#^metadata/chub/[1-9][0-9]*/[1-9][0-9]*$#D', $path))
            || ($method === 'POST' && preg_match('#^data/chub/[1-9][0-9]*/[1-9][0-9]*$#D', $path)))) {
            throw new RuntimeException('Invalid host secret-store operation.');
        }
        $handle = curl_init(rtrim($this->mountUrl, '/').'/'.$path);
        try {
            // Resolve for every request: host-owned renewal/rotation must not leave a cached token.
            $token = $this->hostToken instanceof Closure ? ($this->hostToken)() : $this->hostToken;
            if (! $this->validToken($token)) {
                throw new RuntimeException;
            }
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROXY => '', CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Vault-Token: '.$token],
            ]);
            if ($this->caBundle !== null) {
                curl_setopt($handle, CURLOPT_CAINFO, $this->caBundle);
            }
            if ($method === 'POST') {
                curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
            }
            $response = curl_exec($handle);
            if ($response === false) {
                throw new RuntimeException;
            }
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            // Never return provider error text; it may echo the request.
            return ['status' => $status, 'body' => $status === 200 && $method === 'GET'
                ? json_decode($response, true, 32, JSON_THROW_ON_ERROR) : []];
        } catch (Throwable) {
            throw new RuntimeException('Host secret-store request failed.');
        } finally {
            unset($token);
            curl_close($handle);
        }
    }
}
