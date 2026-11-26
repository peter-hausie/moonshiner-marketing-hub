<?php

namespace App\Console\Commands;

use App\Services\Agents\AgentCredentialRevocationSink;
use App\Services\Agents\ReconcileAgentCredentialBatch;
use Illuminate\Console\Command;
use Throwable;

class ReconcileAgentCredentials extends Command
{
    protected $signature = 'agents:reconcile-credentials {--after=0} {--limit=100}';

    protected $description = 'Reconcile one bounded credential page; no issuance or API activation';

    public function handle(): int
    {
        if (config('agents.credential_store.enabled') !== true) {
            $this->error('Credential reconciliation unavailable: store is not explicitly enabled.');

            return self::FAILURE;
        }

        $after = $this->option('after');
        $limit = $this->option('limit');
        foreach ([$after, $limit] as $value) {
            if (! is_string($value) || ! preg_match('/^(0|[1-9][0-9]*)$/D', $value)
                || filter_var($value, FILTER_VALIDATE_INT) === false) {
                $this->error('Invalid reconciliation page: after must be nonnegative; limit must be 1..100.');

                return self::INVALID;
            }
        }
        if ((int) $limit < 1 || (int) $limit > 100) {
            $this->error('Invalid reconciliation page: after must be nonnegative; limit must be 1..100.');

            return self::INVALID;
        }

        try {
            $result = app(ReconcileAgentCredentialBatch::class)->reconcile(
                app(AgentCredentialRevocationSink::class), (int) $after, (int) $limit,
            );
        } catch (Throwable) {
            $this->error('Credential reconciliation failed; host retry required.');

            return self::FAILURE;
        }

        // Only counts and a database cursor; never backend exceptions or credential values.
        $this->line(json_encode([
            'reconciled' => count($result['reconciled']),
            'unchanged' => count($result['unchanged']),
            'failed' => count($result['failed']),
            'next_after' => $result['next_after'],
        ], JSON_THROW_ON_ERROR));
        $this->line('Repeat failed pages; restart at after=0 after each sweep. A cursor is not a completion receipt.');

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
