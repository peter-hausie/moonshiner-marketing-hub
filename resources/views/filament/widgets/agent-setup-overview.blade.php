<x-filament-widgets::widget>
    <link rel="stylesheet" href="{{ asset('css/agent-setup.css') }}?v=20261123b">
    <x-filament::section>
        <div class="chub-setup">
            <header class="cs-overview-head"><div><h2>Dein Agententeam</h2><p>Was steht schon, was fehlt noch bis zum ersten Lauf?</p></div><a href="{{ \App\Filament\Pages\AgentSetup::getUrl() }}" class="cs-button">Setup bearbeiten <x-heroicon-o-arrow-up-right aria-hidden="true" /></a></header>
            <div class="cs-role-grid">
                @foreach ($agents as $agent)
                    <section class="cs-role" aria-label="{{ $agent['name'] }}">
                        <div class="cs-role-top"><span class="cs-icon">@if ($agent['role'] === 'Recherche')<x-heroicon-o-magnifying-glass aria-hidden="true" />@else<x-heroicon-o-pencil-square aria-hidden="true" />@endif</span><div><h3>{{ $agent['name'] }}</h3><p>{{ $agent['role'] }}</p></div></div>
                        <span class="cs-status {{ $agent['status'] === 'Erfolgreicher Lauf nachgewiesen' ? 'cs-status-good' : '' }}">{{ $agent['status'] }}</span>
                        <div class="cs-next"><strong>Nächster Schritt</strong><p>{{ $agent['next'] }}</p></div>
                        <details class="cs-details"><summary>Einrichtungsschritte anzeigen</summary><ol>
                            @foreach ($agent['checks'] as $check)
                                <li>@if ($check['ok'])<x-heroicon-o-check-circle class="cs-success" aria-hidden="true" />@else<x-heroicon-o-minus-circle aria-hidden="true" />@endif<span>{{ $check['ok'] ? 'Erfüllt' : 'Offen' }}: {{ $check['text'] }}</span></li>
                            @endforeach
                        </ol></details>
                        <p class="cs-footnote">Letzter erfolgreicher Hub-Auftrag:<br>{{ $agent['last_success'] ?? 'Noch nicht nachgewiesen' }}</p>
                    </section>
                @endforeach
            </div>
            <p class="cs-footnote">Geprüft wird die Hub-Anbindung, nicht der externe Agentendienst oder dessen Zeitplan. Manuelle Importe bestätigen keinen automatischen Betrieb.</p>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
