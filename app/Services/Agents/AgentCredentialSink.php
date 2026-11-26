<?php

namespace App\Services\Agents;

/**
 * Trusted host-owned secret store, never an agent callback.
 * Acknowledge only durable masked storage; never print/log the bearer or put it
 * in process arguments, URLs, exception messages or editorial API responses.
 */
interface AgentCredentialSink
{
    public function store(int $principalId, int $credentialId, #[\SensitiveParameter] string $bearer): void;
}
