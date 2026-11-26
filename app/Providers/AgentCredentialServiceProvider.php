<?php

namespace App\Providers;

use App\Services\Agents\AgentCredentialRevocationSink;
use App\Services\Agents\AgentCredentialSink;
use App\Services\Agents\BuildHostCredentialSink;
use Illuminate\Support\ServiceProvider;

/** Explicit host opt-in only; never provisions credentials or enables an agent API. */
class AgentCredentialServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (config('agents.credential_store.enabled') !== true) {
            return;
        }

        $this->app->singleton(AgentCredentialRevocationSink::class, function () {
            return app(BuildHostCredentialSink::class)->build();
        });
        $this->app->alias(AgentCredentialRevocationSink::class, AgentCredentialSink::class);
    }
}
