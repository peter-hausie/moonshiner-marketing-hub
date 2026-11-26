<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/agent-setup.css') }}">
    <div class="chub-setup">
        <p>Langdock Cloud. API Schlüssel im Langdock Workspace erstellen und die beiden Agenten für diesen Schlüssel freigeben. Rechercheagent mit Websuche, Textagent ohne veröffentlichende Aktionen. Der Schlüssel wird verschlüsselt gespeichert und nie wieder angezeigt.</p>
        @if(session('connector_notice'))<p role="status">{{ session('connector_notice') }}</p>@endif
        @if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <form method="POST" action="{{ route('connector.settings') }}" class="cs-panel cs-panel-body">
            @csrf
            <input type="hidden" name="revision" value="{{ $connection['revision'] ?? 0 }}">
            <div class="cs-field"><label for="provider-key">API Schlüssel {{ $hasKey ? '(gespeichert; leer lassen zum Beibehalten)' : '' }}</label><input id="provider-key" type="password" name="api_key" autocomplete="new-password" maxlength="4096" value=""></div>
            <div class="cs-field"><label for="writer-id">Langdock Textagent ID</label><input id="writer-id" name="writer_agent_id" value="{{ $connection['writer_agent_id'] ?? '' }}" maxlength="200"></div>
            <div class="cs-field"><label for="research-id">Langdock Rechercheagent ID (optional)</label><input id="research-id" name="research_agent_id" value="{{ $connection['research_agent_id'] ?? '' }}" maxlength="200"></div>
            <input type="hidden" name="enabled" value="0">
            <label><input type="checkbox" name="enabled" value="1" @checked($connection['enabled'] ?? false)> Connector aktivieren und interne Agentenidentitäten freischalten</label>
            <p>Änderungen stoppen bereits eingereihte Aufträge dieser Konfiguration. Alte vorgemerkte Nachrichten werden nicht rückwirkend gesendet. Neue Aufträge können Anbietergebühren verursachen.</p>
            <x-filament::button type="submit">Speichern</x-filament::button>
        </form>
        <x-filament::button wire:click="testConnection" wire:loading.attr="disabled">Gespeicherte Verbindung testen (API Aufruf)</x-filament::button>
        <section class="cs-panel cs-panel-body">
            <h2>Recherche auslösen</h2><p>Erzeugt bis zu fünf belegte Themenvorschläge. Keine automatische Veröffentlichung. Leere Ergebnisse sind möglich.</p>
            <form wire:submit="startResearch"><div class="cs-field"><label for="research-task">Auftrag</label><textarea id="research-task" wire:model="researchInstruction" maxlength="10000" rows="4"></textarea></div><x-filament::button type="submit" wire:loading.attr="disabled">Recherche starten</x-filament::button></form>
        </section>
        <section class="cs-panel cs-panel-body" wire:poll.10s>
            <h2>Letzte 30 Aufträge</h2>
            <p>Verarbeitung benötigt Scheduler und Queue Worker. Nach unklarem Timeout erfolgt keine automatische kostenpflichtige Wiederholung. Maximal drei manuelle Versuche.</p>
            @forelse($runs as $run)
                <article wire:key="run-{{ $run->id }}"><strong>#{{ $run->id }} · {{ $run->kind === 'research' ? 'Recherche' : 'Text' }} · {{ $run->label() }}</strong>
                <p>{{ $run->error_code }} · Versuch {{ $run->attempt }} · {{ $run->updated_at }}</p>
                @if($run->kind==='research' && $run->status==='succeeded')<p>{{ count($run->result_ids ?? []) }} Themenvorschläge gespeichert.</p>@endif
                @if($run->status==='failed' && $run->attempt<3)<x-filament::button wire:click="retry({{ $run->id }})">Erneut versuchen (API Aufruf)</x-filament::button>@endif
                </article>
            @empty<p>Noch keine Aufträge.</p>@endforelse
        </section>
    </div>
</x-filament-panels::page>
