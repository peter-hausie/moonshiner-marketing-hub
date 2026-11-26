<?php

namespace App\Services\Agents;

use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Host-only KV v2 adapter. Opt-in host binding; no credential provisioning. */
class OpenBaoCredentialSink implements AgentCredentialRevocationSink
{
    public function __construct(private OpenBaoTransport $transport) {}

    public function store(int $principalId, int $credentialId, #[SensitiveParameter] string $bearer): void
    {
        $path = $this->path($principalId, $credentialId);
        try {
            // Never update an existing key, including a revocation-before-store tombstone.
            $result = $this->transport->request('POST', 'data/'.$path, [
                'options' => ['cas' => 0],
                'data' => ['state' => 'active', 'bearer' => $bearer],
            ]);
            if ($result['status'] !== 200) {
                throw new RuntimeException;
            }
        } catch (Throwable) {
            throw new RuntimeException('Host credential storage failed.');
        }
    }

    public function revoke(int $principalId, int $credentialId): void
    {
        $path = $this->path($principalId, $credentialId);
        try {
            for ($attempt = 0; $attempt < 8; $attempt++) {
                // Metadata only: never retrieve the bearer into a response/exception.
                $metadata = $this->transport->request('GET', 'metadata/'.$path);
                $version = $metadata['status'] === 404 ? 0 : ($metadata['body']['data']['current_version'] ?? null);
                if (! in_array($metadata['status'], [200, 404], true) || ! is_int($version) || $version < 0) {
                    throw new RuntimeException;
                }
                $result = $this->transport->request('POST', 'data/'.$path, [
                    'options' => ['cas' => $version], 'data' => ['state' => 'revoked'],
                ]);
                if ($result['status'] === 200) {
                    return;
                }
                // KV v2 reports CAS conflicts as 400. Retry metadata, never blind overwrite.
                if ($result['status'] !== 400) {
                    throw new RuntimeException;
                }
            }
        } catch (Throwable) {
            throw new RuntimeException('Host credential revocation failed.');
        }
        throw new RuntimeException('Host credential revocation failed.');
    }

    private function path(int $principalId, int $credentialId): string
    {
        if ($principalId < 1 || $credentialId < 1) {
            throw new RuntimeException('Invalid host credential identity.');
        }

        return 'chub/'.$principalId.'/'.$credentialId;
    }
}
