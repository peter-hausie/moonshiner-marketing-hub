<?php

namespace App\Services\Agents;

use App\Models\AgentPrincipal;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ReconcileAgentCredentialDelivery
{
    /** Host-only targeted recovery, then durable sink revocation outside DB transactions. */
    public function reconcile(int $deliveryId, AgentCredentialRevocationSink $sink): bool
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Credential reconciliation requires a committed host operation.');
        }

        try {
            app(RecoverAgentCredentialDelivery::class)->recover($deliveryId);
            $this->retireDelivered($deliveryId);
            $delivery = DB::table('agent_credential_deliveries')->find($deliveryId);
            if (! $delivery || $delivery->state !== 'revoked') {
                return false;
            }

            // Revoked is terminal. Never hold a database lock across host-store I/O.
            // Repeating after a crash is required: no distributed completion claim.
            $sink->revoke((int) $delivery->agent_principal_id, (int) $delivery->credential_id);
        } catch (Throwable) {
            throw new RuntimeException('Credential reconciliation failed; host retry required.');
        }

        return true;
    }

    /** Retire acknowledged credentials only after their authority is permanently gone. */
    private function retireDelivered(int $deliveryId): void
    {
        $delivery = DB::table('agent_credential_deliveries')->find($deliveryId);
        if (! $delivery || $delivery->state !== 'delivered') {
            return;
        }

        DB::transaction(function () use ($deliveryId, $delivery): void {
            $principal = AgentPrincipal::query()->lockForUpdate()->findOrFail($delivery->agent_principal_id);
            $current = DB::table('agent_credential_deliveries')->where('id', $deliveryId)->lockForUpdate()->first();
            if ($current->state !== 'delivered') {
                return;
            }
            $token = $principal->tokens()->whereKey($current->credential_id)->lockForUpdate()->first();
            // Disablement is reversible: it must not destroy an otherwise live credential.
            if ($token && ($token->expires_at === null || $token->expires_at->isFuture())) {
                return;
            }
            if ($token && ! $token->delete()) {
                throw new RuntimeException('Credential retirement did not complete.');
            }
            DB::table('agent_credential_deliveries')->where('id', $deliveryId)->update([
                'state' => 'revoked', 'updated_at' => now(),
            ]);
        }, 3);
    }
}
