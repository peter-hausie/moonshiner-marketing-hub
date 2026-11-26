<?php

namespace App\Services\Agents;

use App\Models\AgentPrincipal;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use Throwable;

class DeliverAgentCredential
{
    /**
     * Trusted host only. No default sink binding, CLI or HTTP entry point.
     * Returns a non-secret credential ID, never Sanctum's plaintext object.
     */
    public function deliver(
        AgentPrincipal $principal,
        AgentCredentialSink $sink,
        string $name,
        array $abilities,
        DateTimeInterface $expiresAt,
        ?PersonalAccessToken $previous = null,
    ): int {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Credential delivery requires a committed host operation.');
        }

        // Interface parameter attributes are not inherited by PHP implementations.
        // Reject an unsafe adapter before issuance, rotation or any host handoff.
        $bearerParameter = (new ReflectionMethod($sink, 'store'))->getParameters()[2];
        if ($bearerParameter->getAttributes(SensitiveParameter::class) === []) {
            throw new RuntimeException('Credential sink requires sensitive bearer parameter redaction.');
        }

        // Issuance/rotation and recovery evidence commit together, before host I/O.
        $issued = DB::transaction(function () use ($principal, $previous, $name, $abilities, $expiresAt) {
            $issued = $previous === null
                ? $principal->createToken($name, $abilities, $expiresAt)
                : $principal->rotateToken($previous, $name, $abilities, $expiresAt);
            DB::table('agent_credential_deliveries')->insert([
                'agent_principal_id' => $principal->getKey(),
                'credential_id' => $issued->accessToken->getKey(),
                'state' => 'pending',
                'recover_after' => now()->addMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $issued;
        }, 3);
        $id = (int) $issued->accessToken->getKey();

        try {
            $sink->store((int) $principal->getKey(), $id, $issued->plainTextToken);
            DB::transaction(function () use ($principal, $id): void {
                $current = AgentPrincipal::query()->lockForUpdate()->findOrFail($principal->getKey());
                $delivery = DB::table('agent_credential_deliveries')->where('credential_id', $id)->lockForUpdate()->first();
                if ($delivery->state !== 'pending' || ! $current->tokens()->whereKey($id)->exists()) {
                    throw new RuntimeException('Credential delivery was revoked before acknowledgement.');
                }
                DB::table('agent_credential_deliveries')->where('id', $delivery->id)->update([
                    'state' => 'delivered', 'updated_at' => now(),
                ]);
            }, 3);
        } catch (Throwable) {
            // An ambiguous store acknowledgement must not leave a usable token.
            // Rotation never resurrects the previous token.
            try {
                DB::transaction(function () use ($principal, $id): void {
                    $current = AgentPrincipal::query()->lockForUpdate()->find($principal->getKey());
                    $current?->tokens()->whereKey($id)->delete();
                    DB::table('agent_credential_deliveries')->where('credential_id', $id)->update([
                        'state' => 'revoked', 'updated_at' => now(),
                    ]);
                }, 3);
            } catch (Throwable) {
                // Do not chain sink/SQL exceptions: either may contain secrets.
                throw new RuntimeException('Credential delivery failed; revocation requires host recovery.');
            }

            throw new RuntimeException('Credential delivery failed; replacement revoked.');
        } finally {
            $issued->plainTextToken = '';
            unset($issued);
        }

        return $id;
    }
}
