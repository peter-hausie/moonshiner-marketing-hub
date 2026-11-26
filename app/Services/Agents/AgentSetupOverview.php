<?php

namespace App\Services\Agents;

use App\Models\AgentEditorialSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only readiness evidence; never probes providers or exposes credentials. */
final class AgentSetupOverview
{
    public function rows(): array
    {
        $rows = [];
        foreach ([
            'journalist' => ['Recherche', 'research_api_enabled', 'research:write', 'Quellenfund mit Beleg und Themenvorschlag'],
            'underwood' => ['Textentwurf', 'draft_api_enabled', 'draft:write', 'Bearbeitbarer Entwurf zu einem freigegebenen Themenvorschlag'],
        ] as $identity => [$role, $flag, $ability, $result]) {
            // Older live installations deliberately have only part of the repository schema.
            $schemaReady = Schema::hasTable('agent_principals') && Schema::hasTable('personal_access_tokens');
            $agent = $schemaReady ? DB::table('agent_principals')->where('name', $identity)->first() : null;
            $credential = $agent ? DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\AgentPrincipal')->where('tokenable_id', $agent->id)
                ->where('expires_at', '>', now())->get(['abilities', 'expires_at'])
                ->contains(function ($token) use ($ability): bool {
                    $abilities = json_decode($token->abilities, true);

                    return is_array($abilities) && in_array($ability, $abilities, true)
                        && array_diff($abilities, [$ability]) === [];
                }) : false;
            $api = config('agents.'.$flag) === true;
            $lastSuccess = $agent && Schema::hasTable('agent_jobs')
                ? DB::table('agent_jobs')->where('agent_principal_id', $agent->id)->where('status', 'succeeded')->max('updated_at') : null;
            // Internal provider connector does not need the optional public bearer APIs.
            $connector = Schema::hasTable('provider_connections')
                ? DB::table('provider_connections')->where('id', 1)->select('enabled', 'revision', 'writer_agent_id', 'research_agent_id')
                    ->selectRaw('api_key IS NOT NULL AS has_key')->first() : null;
            if ($connector) {
                $credential = (bool) $connector->has_key && (bool) ($identity === 'journalist' ? $connector->research_agent_id : $connector->writer_agent_id);
                $api = (bool) $connector->enabled;
                $lastSuccess = Schema::hasTable('connector_runs') ? DB::table('connector_runs')
                    ->where('kind', $identity === 'journalist' ? 'research' : 'draft')
                    ->where('connection_revision', $connector->revision)->whereIn('status', ['succeeded', 'adopted'])->max('updated_at') : null;
            }
            $editorial = Schema::hasTable('agent_editorial_settings')
                ? AgentEditorialSetting::find($identity === 'journalist' ? 'research' : 'writer') : null;
            $briefed = $editorial && trim($editorial->briefing ?? '') !== '';
            $monitoring = $identity !== 'journalist' || ($editorial && (! $editorial->monitoring_required || count($editorial->competitors ?? []) > 0));
            $checks = [
                ['ok' => (bool) $briefed, 'text' => $identity === 'journalist' ? 'Recherche-Briefing im Agenten-Setup hinterlegen' : 'Tone-of-Voice-Briefing im Agenten-Setup hinterlegen'],
                ['ok' => (bool) $monitoring, 'text' => 'Mitbewerber hinterlegen oder Monitoring bewusst abwählen'],
                ['ok' => $schemaReady, 'text' => 'Agenten-Datenmodell bereitstellen'],
                ['ok' => (bool) $agent?->enabled, 'text' => 'Eigenen Agentenzugang für diese Installation einrichten und aktivieren'],
                ['ok' => $credential, 'text' => $connector ? 'Langdock Schlüssel und passende Agenten ID im Backend hinterlegen' : 'Gültigen, auf diese Rolle begrenzten Zugang geschützt hinterlegen und erneuern'],
                ['ok' => $api, 'text' => $connector ? 'Langdock Connector im Backend aktivieren' : 'Passende Hub-Schnittstelle kontrolliert aktivieren'],
                ['ok' => (bool) $lastSuccess, 'text' => 'Testauftrag vollständig ausführen und Ergebnis im Hub prüfen'],
            ];
            if ($identity !== 'journalist') {
                unset($checks[1]);
                $checks = array_values($checks);
            }
            $ready = $briefed && $monitoring && $schemaReady && $agent?->enabled && $credential && $api;
            $rows[] = [
                'name' => $editorial?->display_name ?? config('agents.labels.'.$identity, $role), 'role' => $role, 'result' => $result,
                'status' => ! $ready ? 'Setup erforderlich' : ($lastSuccess ? 'Erfolgreicher Lauf nachgewiesen' : 'Testlauf fehlt'),
                'checks' => $checks, 'last_success' => $lastSuccess,
                'next' => collect($checks)->firstWhere('ok', false)['text'] ?? 'Zeitplan und Überwachung im ausführenden Agentensystem prüfen',
            ];
        }

        return $rows;
    }
}
