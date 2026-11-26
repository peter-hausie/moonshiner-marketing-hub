<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/editorial-workspace.css') }}?v=20261123">
    <div class="ew" wire:poll.10s>
        <header class="ew-hero"><div><span class="ew-eyebrow">Textwerkstatt · {{ $writer?->display_name ?: 'Text-Agent' }}</span><h2>Gute Texte entstehen im Dialog.</h2><p>{{ $piece->title }}</p></div><span class="ew-hero-icon"><x-heroicon-o-chat-bubble-left-right aria-hidden="true" /></span></header>
        <div class="ew-notice" role="status"><strong>{{ $connected ? 'Connector aktiviert' : 'Agent noch nicht angebunden' }}</strong><span>{{ $connected ? 'Neue Aufträge werden im Hintergrund verarbeitet. Ergebnisse bleiben Vorschläge, bis du sie übernimmst.' : 'Nachrichten werden vorgemerkt und nicht versendet. Administratoren konfigurieren Langdock unter Langdock Verbindung.' }}</span></div>
        <div class="ew-layout">
            <div class="ew-stack">
                <section class="ew-panel"><h3>Was soll am Text passieren?</h3>
                    <form wire:submit="saveMessage" class="ew-compose">
                        <label for="message-kind">Aufgabe</label><select id="message-kind" wire:model="kind"><option value="draft">Beitrag ausformulieren</option><option value="feedback">Feedback / Überarbeitung</option></select>
                        @error('kind')<p class="ew-error" role="alert">{{ $message }}</p>@enderror
                        <label for="agent-message">Dein Briefing oder Feedback</label>
                        <textarea id="agent-message" wire:model="message" rows="6" maxlength="10000" required placeholder="Formuliere daraus einen LinkedIn-Beitrag. Einstieg mit einer konkreten Alltagssituation, maximal 1.500 Zeichen …"></textarea>
                        @error('message')<p class="ew-error" role="alert">{{ $message }}</p>@enderror
                        <div class="ew-prompts"><span>Zum Beispiel:</span><button type="button" x-on:click="$wire.set('kind', 'feedback'); $wire.set('message', 'Bitte schreibe den Text humorvoller, ohne die fachliche Aussage zu verändern.')">Humorvoller</button><button type="button" x-on:click="$wire.set('kind', 'feedback'); $wire.set('message', 'Bitte kürze den Text und bringe die wichtigste Aussage an den Anfang.')">Kürzer &amp; klarer</button><button type="button" x-on:click="$wire.set('kind', 'feedback'); $wire.set('message', 'Bitte ergänze einen konkreten nächsten Schritt für die Leserinnen und Leser.')">Konkreter</button></div>
                        <p class="ew-muted">Verwendet den zuletzt gespeicherten Beitrag und das Tone-of-Voice-Briefing. Änderungen im Editor bitte vorher speichern.</p>
                        <button class="ew-button" type="submit" wire:loading.attr="disabled" wire:target="saveMessage"><span wire:loading.remove wire:target="saveMessage">Auftrag vormerken</span><span wire:loading wire:target="saveMessage">Wird gespeichert …</span></button>
                    </form>
                </section>
                <section class="ew-panel"><span class="ew-eyebrow">Briefings &amp; Feedback</span><h3>Der Verlauf zu diesem Beitrag</h3>
                    @forelse($messages as $entry)
                        <article class="ew-message" wire:key="agent-message-{{ $entry->id }}"><div class="ew-meta"><strong>{{ $entry->kind === 'draft' ? 'Ausformulierung' : 'Feedback' }}</strong><time>{{ $entry->created_at->timezone('Europe/Vienna')->format('d.m.Y · H:i') }}</time></div><p class="ew-prose">{{ $entry->message }}</p>@php($run = $runs->get($entry->id))
                            <span class="ew-badge">{{ $run?->label() ?? 'Vorgemerkt · nicht gesendet' }}</span>
                            @if($run?->error_code)<p role="alert">{{ $run->error_code }}. Bei Konflikten aktuellen Beitrag prüfen und neuen Auftrag erstellen.</p>@endif
                            @if($run?->status === 'failed' && $run->attempt < 3)<button class="ew-button" wire:click="retryRun({{ $run->id }})" wire:loading.attr="disabled">Erneut versuchen (API Aufruf)</button>@endif
                            @if($run?->result_version_id && ($proposal = $proposals->get($run->result_version_id)))
                                <section><h4>Agentenvorschlag: {{ $proposal->title }}</h4><p class="ew-prose">{{ $proposal->body }}</p>
                                @if($run->status === 'succeeded')<button class="ew-button" wire:click="adoptRun({{ $run->id }})" wire:confirm="Vorschlag als neuen Entwurf übernehmen? Dies ist keine Veröffentlichung." wire:loading.attr="disabled">Vorschlag übernehmen</button>@endif</section>
                            @endif<details><summary>Gespeicherten Arbeitsstand ansehen</summary><p><strong>{{ $entry->context_snapshot['title'] }}</strong></p><p class="ew-prose">{{ $entry->context_snapshot['body'] }}</p><h4>Tone of Voice</h4><p class="ew-prose">{{ $entry->context_snapshot['tone_of_voice'] ?: 'Noch kein Markenbriefing hinterlegt.' }}</p></details></article>
                    @empty<div class="ew-empty"><x-heroicon-o-chat-bubble-left-ellipsis aria-hidden="true" /><h4>Hier beginnt die Zusammenarbeit.</h4><p>Formuliere den ersten Auftrag. Spätere Änderungswünsche bleiben im selben Beitragsverlauf.</p></div>@endforelse
                    {{ $messages->links() }}
                </section>
            </div>
            <aside class="ew-stack"><section class="ew-panel"><span class="ew-eyebrow">Arbeitsgrundlage</span><h3>{{ $piece->title }}</h3><p>{{ implode(' · ', $piece->channels ?? []) ?: 'Noch keine Kanäle gewählt' }}</p><details><summary>Aktuellen Text ansehen</summary><p class="ew-prose">{{ $piece->body }}</p></details><details><summary>Kontext &amp; Briefing</summary><p class="ew-prose">{{ $piece->context ?: 'Noch kein Briefing hinterlegt.' }}</p></details><a class="ew-link" href="{{ \App\Filament\Resources\ContentItems\ContentItemResource::getUrl('edit', ['record' => $piece]) }}">← Zum Editor</a></section>
                <section class="ew-panel"><span class="ew-eyebrow">Die Stimme deiner Marke</span><h3>{{ $writer?->display_name ?: 'Text-Agent' }}</h3><p class="ew-prose">{{ \Illuminate\Support\Str::limit($writer?->briefing ?: 'Hinterlege im Agenten-Setup, wie eure Marke klingen soll.', 360) }}</p>@if(auth()->user()->isCurator())<a class="ew-link" href="{{ \App\Filament\Pages\AgentSetup::getUrl() }}">Agenten-Setup öffnen →</a>@endif</section>
            </aside>
        </div>
    </div>
</x-filament-panels::page>
