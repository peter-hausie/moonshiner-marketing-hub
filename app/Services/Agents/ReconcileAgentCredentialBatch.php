<?php

namespace App\Services\Agents;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ReconcileAgentCredentialBatch
{
    /**
     * Host-only bounded sweep; restart at zero after next_after becomes null.
     * IDs are progress, not completion receipts. Failed IDs must be retried on
     * the next sweep (or individually); never persist a permanent high-water mark.
     *
     * @return array{next_after: ?int, reconciled: array, unchanged: array, failed: array}
     */
    public function reconcile(AgentCredentialRevocationSink $sink, int $afterId = 0, int $limit = 100): array
    {
        if ($afterId < 0 || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Invalid credential reconciliation page.');
        }
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Credential reconciliation requires a committed host operation.');
        }

        try {
            // Scan every state: live entries can expire after this sweep; revoked
            // entries must remain eligible after ambiguous host acknowledgements.
            $ids = DB::table('agent_credential_deliveries')->where('id', '>', $afterId)
                ->orderBy('id')->limit($limit + 1)->pluck('id');
        } catch (Throwable) {
            throw new RuntimeException('Credential reconciliation scan failed; host retry required.');
        }
        $page = $ids->take($limit);
        $result = [
            'next_after' => $ids->count() > $limit ? (int) $page->last() : null,
            'reconciled' => [], 'unchanged' => [], 'failed' => [],
        ];
        foreach ($page as $id) {
            try {
                $changed = app(ReconcileAgentCredentialDelivery::class)->reconcile((int) $id, $sink);
                $result[$changed ? 'reconciled' : 'unchanged'][] = (int) $id;
            } catch (Throwable) {
                // No backend text, chained exception or token material in reports.
                $result['failed'][] = (int) $id;
            }
        }

        return $result;
    }
}
