<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class AgentPrincipal extends Authenticatable
{
    use HasApiTokens { createToken as private createSanctumToken; }

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function allowedAbilities(): array
    {
        return match ($this->name) {
            'journalist' => ['research:write'],
            'underwood' => ['draft:write'],
            default => [],
        };
    }

    /** Trusted host-side issuance only; never expose through agent endpoints. */
    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): NewAccessToken
    {
        return DB::transaction(function () use ($name, $abilities, $expiresAt): NewAccessToken {
            $agent = $this->lockedIssuingPrincipal($abilities, $expiresAt);

            return $agent->createSanctumToken($name, $abilities, $expiresAt);
        }, 3);
    }

    /** Atomically replace one owned credential without increasing its scopes. */
    public function rotateToken(PersonalAccessToken $previous, string $name, array $abilities, DateTimeInterface $expiresAt): NewAccessToken
    {
        return DB::transaction(function () use ($previous, $name, $abilities, $expiresAt): NewAccessToken {
            // Same principal -> token lock order as authenticated acceptance.
            $agent = $this->lockedIssuingPrincipal($abilities, $expiresAt);
            $token = $agent->tokens()->whereKey($previous->getKey())->lockForUpdate()->first();
            if (! $token || ! hash_equals($previous->token, $token->token)
                || ! is_array($token->abilities) || $token->abilities === []
                || array_diff($token->abilities, $agent->allowedAbilities()) !== []
                || array_diff($abilities, $token->abilities) !== []) {
                throw new AuthorizationException('Agent credential policy rejected rotation.');
            }

            $replacement = $agent->createSanctumToken($name, $abilities, $expiresAt);
            if (! $token->delete()) {
                throw new \RuntimeException('Agent credential revocation did not complete.');
            }

            return $replacement;
        }, 3);
    }

    private function lockedIssuingPrincipal(array $abilities, ?DateTimeInterface $expiresAt): self
    {
        $agent = $this->exists ? self::query()->lockForUpdate()->find($this->getKey()) : null;
        if (! $agent || ! $agent->enabled || $abilities === []
            || array_diff($abilities, $agent->allowedAbilities()) !== []
            || $expiresAt === null || $expiresAt <= now() || $expiresAt > now()->addDay()) {
            throw new AuthorizationException('Agent credential policy rejected issuance.');
        }

        return $agent;
    }
}
