<?php

namespace App\Console\Commands;

use App\Services\Agents\AgentCredentialSink;
use App\Services\Agents\BuildHostCredentialSink;
use App\Services\Agents\OpenBaoAgentFileToken;
use Illuminate\Console\Command;
use Throwable;

class CheckAgentCredentialHost extends Command
{
    protected $signature = 'agents:check-credential-host {--prepare : Validate disabled host configuration without enabling delivery}';

    protected $description = 'Read-only local credential host preflight; no remote authentication or issuance';

    public function handle(): int
    {
        $preparing = $this->option('prepare');
        if ($preparing ? config('agents.credential_store.enabled') !== false : config('agents.credential_store.enabled') !== true) {
            $this->error($preparing
                ? 'Credential host preparation requires an explicitly disabled store.'
                : 'Credential host preflight unavailable: store is not explicitly enabled.');

            return self::FAILURE;
        }

        try {
            if (! extension_loaded('curl') || ! function_exists('posix_geteuid')) {
                throw new \RuntimeException;
            }
            // Reuse production configuration validation; resolving the sink makes no requests.
            if ($preparing) {
                app(BuildHostCredentialSink::class)->build();
            } else {
                app(AgentCredentialSink::class);
            }
            $path = config('agents.credential_store.token_file');
            if (! is_string($path)) {
                throw new \RuntimeException;
            }
            // Discard immediately; never report paths, values, lengths or backend exceptions.
            (new OpenBaoAgentFileToken($path))();
        } catch (Throwable) {
            $this->error('Credential host preflight failed: check runtime, configuration and private Agent sink.');

            return self::FAILURE;
        }

        $this->info('Local credential host preflight passed. Remote authentication, ACL, renewal and audit capacity remain unverified.');

        return self::SUCCESS;
    }
}
