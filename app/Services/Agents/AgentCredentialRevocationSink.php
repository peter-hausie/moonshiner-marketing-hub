<?php

namespace App\Services\Agents;

/** Trusted host adapter; revocation must durably fence later stores of this exact ID. */
interface AgentCredentialRevocationSink extends AgentCredentialSink
{
    /**
     * Idempotently remove only this principal/credential pair and retain a tombstone.
     * A concurrent/late store must not recreate it. Never expose secret-store errors.
     */
    public function revoke(int $principalId, int $credentialId): void;
}
