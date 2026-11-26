<?php

namespace App\Services\Connector;

use App\Models\AgentPrincipal;
use App\Models\ProviderConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SaveConnection
{
    public function save(User $user, array $input): void
    {
        abort_unless($user->canManageAccounts(), 403);
        validator($input, [
            'revision' => 'required|integer|min:0', 'enabled' => 'required|boolean',
            'api_key' => 'nullable|string|max:4096|regex:/\A[^\s]+\z/',
            'writer_agent_id' => 'nullable|string|max:200|regex:/\A[a-zA-Z0-9_-]+\z/',
            'research_agent_id' => 'nullable|string|max:200|regex:/\A[a-zA-Z0-9_-]+\z/',
        ])->validate();
        DB::transaction(function () use ($input) {
            $connection = ProviderConnection::whereKey(1)->lockForUpdate()->first();
            abort_unless(($connection?->revision ?? 0) == (int) $input['revision'], 409, 'Konfiguration geändert. Bitte neu laden.');
            $connection ??= new ProviderConnection;
            $key = trim($input['api_key'] ?? '');
            if ($key !== '') {
                $connection->api_key = $key;
            }
            $connection->forceFill(['id' => 1, 'enabled' => (bool) $input['enabled'],
                'writer_agent_id' => ($input['writer_agent_id'] ?? null) ?: null,
                'research_agent_id' => ($input['research_agent_id'] ?? null) ?: null,
                'revision' => ($connection->revision ?? 0) + 1]);
            abort_if($connection->enabled && (! $connection->api_key || ! $connection->writer_agent_id), 422, 'API Schlüssel und Textagent fehlen.');
            $connection->save();
            // Explicit administrator activation provisions host identities, not public API tokens.
            if ($connection->enabled) {
                foreach (['underwood' => $connection->writer_agent_id, 'journalist' => $connection->research_agent_id] as $name => $id) {
                    if (! $id) {
                        continue;
                    }
                    $agent = AgentPrincipal::where('name', $name)->first() ?? new AgentPrincipal;
                    $agent->forceFill(['name' => $name, 'enabled' => true])->save();
                }
            }
        });
    }
}
