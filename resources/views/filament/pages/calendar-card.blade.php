@php($movable = in_array($item->status, [\App\Enums\ContentStatus::Draft, \App\Enums\ContentStatus::Submitted, \App\Enums\ContentStatus::InReview, \App\Enums\ContentStatus::Rejected], true))
<article class="chub-card status-{{ $item->status->value }}" wire:key="calendar-card-{{ $item->id }}" @if($movable) draggable="true" x-on:dragstart.stop="dragged = { id: {{ $item->id }}, revision: '{{ \App\Filament\Pages\EditorialCalendar::revision($item) }}' }; $event.dataTransfer.effectAllowed='move'; $event.dataTransfer.setData('text/plain', String({{ $item->id }}))" x-on:dragend="dragged=null; hoverDay=null" @endif>
    <div class="chub-card-top"><span class="chub-status">{{ $item->status->label() }}</span>@if($item->scheduled_at)<time>{{ $item->scheduled_at->timezone('Europe/Vienna')->format('H:i') }}</time>@endif @if($movable)<span class="chub-grip" aria-label="Verschiebbar" title="Auf einen anderen Tag ziehen">⠿</span>@endif</div>
    <a class="chub-card-title" draggable="false" href="{{ \App\Filament\Resources\ContentItems\ContentItemResource::getUrl('edit', ['record' => $item]) }}">{{ $item->title }}</a>
    <div class="chub-channels">@forelse($item->channels ?? [] as $channel)
        @php($brand = ['linkedin'=>'linkedin','instagram'=>'instagram','facebook'=>'facebook'][strtolower($channel)] ?? null)
        <span class="chub-channel" title="{{ $channel }}">@if($brand)<img src="{{ asset('images/channels/'.$brand.'.svg') }}" alt="" width="16" height="16">@elseif(strtolower($channel)==='newsletter')<x-heroicon-o-envelope aria-hidden="true" />@else<x-heroicon-o-globe-alt aria-hidden="true" />@endif<span>{{ $channel }}</span></span>
    @empty<span class="chub-muted">Kanal noch offen</span>@endforelse</div>
    @php($owner = $item->responsible)
    <div class="chub-owner" title="{{ $owner?->email ?? 'Im Editor zuweisen' }}"><span class="chub-avatar">{{ $owner ? mb_strtoupper(mb_substr($owner->name,0,1)) : '–' }}</span><span>{{ $owner?->name ?? 'Noch nicht zugewiesen' }}@if($owner && !$owner->active) <small>(inaktiv)</small>@endif</span></div>
    @if($movable)<button type="button" class="chub-plan" wire:click="mountAction('plan', { id: {{ $item->id }} })">{{ $item->scheduled_at ? 'Termin ändern / entfernen' : 'Termin setzen' }}</button>@endif
</article>
