<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/editorial-calendar.css') }}?v=20261123">
    <div class="chub-calendar-workspace" x-data="{ dragged:null, hoverDay:null, busy:false, async drop(day) { if(!this.dragged || this.busy) return; const card=this.dragged; this.dragged=null; this.hoverDay=null; this.busy=true; try { await $wire.dropPiece(card.id, day, card.revision); } catch(e) { alert('Termin konnte nicht gespeichert werden. Bitte neu laden und erneut versuchen.'); } finally { this.busy=false; } } }">
    <p>Ziehe Beiträge auf einen Tag oder zurück in den Ideenstapel. Am Handy und per Tastatur nutze „Termin setzen“.</p>
    <p>Redaktionelle Planung · Zeitzone Wien. Ein Termin ist keine Veröffentlichungsfreigabe.</p>
    <x-filament::section heading="Noch ohne Termin">
        <div class="chub-unscheduled chub-dropzone" :class="{ 'chub-drop-active': hoverDay==='backlog' }" x-on:dragover.prevent="if(dragged) hoverDay='backlog'" x-on:dragleave.self="hoverDay=null" x-on:drop.prevent.stop="drop(null)">
            @forelse ($unscheduled as $item)
                @include('filament.pages.calendar-card', ['item' => $item])
            @empty
                <p>Alle Entwürfe haben einen Redaktionstermin.</p>
            @endforelse
        </div>
    </x-filament::section>
    <div style="display:flex; gap:1rem; align-items:center">
        <x-filament::button wire:click="move(-1)" color="gray">← Drei Monate zurück</x-filament::button>
        <x-filament::button wire:click="move(1)" color="gray">Drei Monate weiter →</x-filament::button>
    </div>
    <div style="overflow-x:auto">
        <table class="chub-calendar">
            <caption class="sr-only">Redaktionskalender: Monate als Spalten, Tage als Zeilen</caption>
            <thead><tr><th scope="col">Tag</th>@foreach ($months as $month)<th scope="col">{{ $month->locale('de')->isoFormat('MMMM YYYY') }}</th>@endforeach</tr></thead>
            <tbody>
            @for ($day = 1; $day <= 31; $day++)
                <tr><th scope="row">{{ $day }}</th>
                    @foreach ($months as $month)
                        <td @if ($day > $month->daysInMonth) class="chub-no-day" @else class="chub-dropzone" :class="{ 'chub-drop-active': hoverDay==='{{ $month->day($day)->format('Y-m-d') }}' }" x-on:dragover.prevent="if(dragged) hoverDay='{{ $month->day($day)->format('Y-m-d') }}'" x-on:dragleave.self="hoverDay=null" x-on:drop.prevent.stop="drop('{{ $month->day($day)->format('Y-m-d') }}')" @endif>
                            @if ($day <= $month->daysInMonth)
                                @php($date = $month->day($day))
                                <span class="chub-weekday">{{ $date->locale('de')->isoFormat('dd') }}</span>
                                @foreach ($planned->get($date->format('Y-m-d'), []) as $item)
                                    @include('filament.pages.calendar-card', ['item' => $item])
                                @endforeach
                            @else
                                <span aria-label="Diesen Tag gibt es in diesem Monat nicht">—</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endfor
            </tbody>
        </table>
    </div>
    <p class="chub-saving" x-show="busy" x-cloak role="status">Termin wird gespeichert …</p>
    </div>
</x-filament-panels::page>
