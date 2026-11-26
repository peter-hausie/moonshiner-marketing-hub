<?php

namespace App\Services\Agents;

use App\Models\AgentPrincipal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RecoverAgentCredentialDelivery
{
    /** Trusted host only. Expired unacknowledged delivery is revoked, never retried. */
    public function recover(int $deliveryId): bool
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Credential recovery requires a committed host operation.');
        }

        $delivery = DB::table('agent_credential_deliveries')->find($deliveryId);
        if (! $delivery) {
            return false;
        }

        return DB::transaction(function () use ($deliveryId, $delivery): bool {
            $principal = AgentPrincipal::query()->lockForUpdate()->findOrFail($delivery->agent_principal_id);
            $current = DB::table('agent_credential_deliveries')->where('id', $deliveryId)->lockForUpdate()->first();
            if ($current->state !== 'pending' || now()->lt($current->recover_after)) {
                return false;
            }

            $principal->tokens()->whereKey($current->credential_id)->delete();
            DB::table('agent_credential_deliveries')->where('id', $deliveryId)->update([
                'state' => 'revoked', 'updated_at' => now(),
            ]);

            return true;
        }, 3);
    }
}
