<?php

namespace App\Services\Agents;

/** Trusted host bookkeeping only; never dispatches or accepts agent results. */
final class DraftJobLifecycle extends AgentJobLifecycle
{
    protected function agentName(): string
    {
        return 'underwood';
    }
}
