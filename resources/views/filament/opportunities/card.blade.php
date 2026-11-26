@php($topic = $getRecord())
<article class="ew-topic">
    <div class="ew-meta"><span class="ew-badge">{{ str_starts_with($topic->title, '[DEMO]') ? 'Demo-Idee' : 'Redaktioneller Impuls' }}</span><span>{{ $topic->created_at?->format('d.m.Y') }}</span></div>
    <h3>{{ $topic->title }}</h3>
    <p>{{ \Illuminate\Support\Str::limit($topic->rationale, 240) }}</p>
    <footer><span>{{ $topic->researchItems->count() }} {{ $topic->researchItems->count() === 1 ? 'Quelle' : 'Quellen' }}</span><span>{{ $topic->contentItems->isNotEmpty() ? 'Bereits übernommen' : 'Bereit zur Auswahl' }}</span></footer>
    <div class="ew-card-actions"><button type="button" class="ew-button" wire:click="adoptTopic({{ $topic->id }})" wire:loading.attr="disabled" wire:target="adoptTopic">{{ $topic->contentItems->isNotEmpty() ? 'Beitrag öffnen' : 'Übernehmen' }} →</button><a class="ew-link" href="{{ \App\Filament\Resources\Opportunities\OpportunityResource::getUrl('view', ['record' => $topic]) }}">Details ansehen</a></div>
</article>
