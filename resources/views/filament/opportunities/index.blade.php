<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/editorial-workspace.css') }}?v=20261123">
    <div class="ew">
        <header class="ew-hero"><div><span class="ew-eyebrow">Ideenraum</span><h2>Aus einem Impuls wird ein guter Beitrag.</h2><p>Prüfe den Ansatz, entdecke die Quellen und übernimm passende Ideen in deine Redaktion.</p></div><span class="ew-hero-icon"><x-heroicon-o-light-bulb aria-hidden="true" /></span></header>
        <div class="ew-topic-table">{{ $this->table }}</div>
    </div>
</x-filament-panels::page>
