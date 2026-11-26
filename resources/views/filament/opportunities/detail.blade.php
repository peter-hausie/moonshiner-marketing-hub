<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/editorial-workspace.css') }}?v=20261123">
    @php($topic = $this->getRecord())
    <div class="ew">
        <header class="ew-hero"><div><span class="ew-eyebrow">Themenvorschlag · {{ str_starts_with($topic->title, '[DEMO]') ? 'Demo' : 'Redaktion' }}</span><h2>{{ $topic->title }}</h2><p>Ein Ansatz für deine Redaktion. Noch kein fertiger Text und keine Veröffentlichungsfreigabe.</p></div></header>
        <div class="ew-layout">
            <div class="ew-stack">
                <section class="ew-panel"><span class="ew-eyebrow">Der Ansatz</span><h3>Warum dieses Thema?</h3><p class="ew-prose">{{ $topic->rationale }}</p></section>
                <section class="ew-panel"><span class="ew-eyebrow">Nachvollziehbar bleiben</span><h3>Recherche &amp; Originalquellen</h3>
                    @forelse($topic->researchItems as $source)
                        <article class="ew-source"><h4>{{ $source->title }}</h4><p class="ew-prose">{{ $source->excerpt }}</p>
                        @if(in_array(parse_url($source->source_url, PHP_URL_SCHEME), ['http', 'https'], true))<a class="ew-link" href="{{ $source->source_url }}" target="_blank" rel="noopener noreferrer">{{ $source->source_url }} ↗</a>@endif
                        <small>{{ $source->observed_at?->format('d.m.Y') }} · {{ $source->provenance }}</small></article>
                    @empty<p class="ew-muted">Redaktionelle Idee ohne externe Recherchequelle. Fakten vor Veröffentlichung ergänzen und prüfen.</p>@endforelse
                </section>
            </div>
            <aside class="ew-panel ew-next"><span class="ew-eyebrow">Nächster Schritt</span><h3>Mach einen Beitrag daraus.</h3><p>Übernimm Titel, Briefing und Quellen in einen bearbeitbaren Entwurf. Danach kannst du den Text-Agenten briefen.</p>
                <button type="button" class="ew-button" wire:click="adopt" wire:loading.attr="disabled" wire:target="adopt">{{ $topic->contentItems->isNotEmpty() ? 'Beitrag öffnen' : 'Übernehmen' }} <span aria-hidden="true">→</span></button>
                <small>Erneutes Übernehmen öffnet den vorhandenen Beitrag. Deine Bearbeitungen bleiben erhalten.</small>
                @foreach($topic->contentItems as $item)<a class="ew-link" href="{{ \App\Filament\Resources\ContentItems\ContentItemResource::getUrl('edit', ['record' => $item]) }}">{{ $item->title }} →</a>@endforeach
                <a class="ew-link" href="{{ \App\Filament\Resources\Opportunities\OpportunityResource::getUrl('index') }}">← Alle Themenvorschläge</a>
            </aside>
        </div>
    </div>
</x-filament-panels::page>
