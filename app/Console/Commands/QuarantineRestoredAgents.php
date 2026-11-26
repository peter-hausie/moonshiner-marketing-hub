<?php

namespace App\Console\Commands;

use App\Models\AgentPrincipal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class QuarantineRestoredAgents extends Command
{
    protected $signature = 'agents:quarantine-restored {--confirm-isolated : Confirm ingress, workers and provisioning are stopped}';

    protected $description = 'Disable restored agent principals and revoke all their local tokens before recovery';

    public function handle(): int
    {
        if (! $this->option('confirm-isolated') || config('agents.research_api_enabled') !== false
            || DB::transactionLevel() !== 0) {
            $this->error('Recovery requires isolation confirmation, disabled research API and no enclosing transaction.');

            return self::FAILURE;
        }

        try {
            $counts = DB::transaction(function (): array {
                // Same principal-before-token order as issuance and authenticated writes.
                // Operational isolation is mandatory: this is not a provisioning lock.
                $agents = AgentPrincipal::query()->orderBy('id')->lockForUpdate()->get();
                $tokens = 0;
                foreach ($agents as $agent) {
                    $agent->forceFill(['enabled' => false])->saveOrFail();
                    $tokens += $agent->tokens()->delete();
                }

                return ['principals' => $agents->count(), 'revoked_tokens' => $tokens];
            }, 3);
        } catch (Throwable) {
            // Never print database/backend details or credential material.
            $this->error('Agent recovery quarantine failed; keep isolation and retry.');

            return self::FAILURE;
        }

        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));
        $this->info('Local quarantine committed. Keep isolation until store cleanup and fresh issuance complete.');

        return self::SUCCESS;
    }
}
