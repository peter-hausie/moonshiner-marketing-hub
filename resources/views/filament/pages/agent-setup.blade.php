<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/agent-setup.css') }}?v=20261123b">
    <div class="chub-setup">
        <header class="cs-intro">
            <span class="cs-icon"><x-heroicon-o-adjustments-horizontal aria-hidden="true" /></span>
            <div><h2>Dein Redaktionsteam. Deine Regeln.</h2><p>Zwei feste Rollen, individuelle Namen und ein gemeinsames Briefing für eure Marke.</p></div>
        </header>
        <form wire:submit="save" class="cs-form">
            <section class="cs-panel" aria-labelledby="writer-heading">
                <header class="cs-panel-header"><span class="cs-icon"><x-heroicon-o-pencil-square aria-hidden="true" /></span><div><h3 id="writer-heading">Text &amp; Tonalität</h3><p>Gib deinem Text-Agenten einen Namen und eurer Marke eine klare Stimme.</p></div></header>
                <div class="cs-panel-body">
                    <div class="cs-field cs-field-name"><label for="writerName">Name des Text-Agenten</label><input id="writerName" wire:model="writerName" maxlength="100" required autocomplete="off">@error('writerName') <p class="cs-error" role="alert">{{ $message }}</p> @enderror</div>
                    <div class="cs-brief-layout">
                        <div class="cs-field"><label for="tone">Tone-of-Voice-Briefing</label><p id="tone-hint" class="cs-hint">Beschreibe, wie eure Texte klingen sollen. Gute Beispiele helfen mehr als einzelne Schlagwörter.</p><textarea id="tone" wire:model="tone" rows="9" aria-describedby="tone-hint" placeholder="Wir schreiben für …&#10;Unsere Leser sprechen wir mit … an.&#10;Unser Ton ist …&#10;Ein guter Beispielsatz: …"></textarea>@error('tone') <p class="cs-error" role="alert">{{ $message }}</p> @enderror</div>
                        <aside class="cs-guide"><strong>Das gehört ins Briefing</strong><ul><li>Zielgruppe und Du-/Sie-Ansprache</li><li>Tonalität und Satzlänge</li><li>Beispiele, die nach euch klingen</li><li>Begriffe und Formulierungen, die ihr vermeidet</li></ul></aside>
                    </div>
                </div>
            </section>
            <section class="cs-panel" aria-labelledby="research-heading">
                <header class="cs-panel-header"><span class="cs-icon"><x-heroicon-o-magnifying-glass aria-hidden="true" /></span><div><h3 id="research-heading">Recherche &amp; Marktbeobachtung</h3><p>Lege fest, welche Inhalte relevant sind und welche Mitbewerber im Blick bleiben.</p></div></header>
                <div class="cs-panel-body">
                    <div class="cs-field cs-field-name"><label for="researchName">Name des Recherche-Agenten</label><input id="researchName" wire:model="researchName" maxlength="100" required autocomplete="off">@error('researchName') <p class="cs-error" role="alert">{{ $message }}</p> @enderror</div>
                    <div class="cs-brief-layout">
                        <div class="cs-field"><label for="researchBrief">Recherche-Briefing</label><p class="cs-hint" id="research-hint">Welche Entwicklungen sind für eure Zielgruppe wirklich interessant?</p><textarea id="researchBrief" wire:model="researchBrief" rows="6" aria-describedby="research-hint" placeholder="Unsere Schwerpunkte sind …&#10;Wir beobachten besonders …&#10;Nicht relevant sind …"></textarea>@error('researchBrief') <p class="cs-error" role="alert">{{ $message }}</p> @enderror</div>
                        <aside class="cs-guide"><strong>Recherche eingrenzen</strong><ul><li>Themen und Zielgruppen</li><li>Märkte und Sprachen</li><li>Bevorzugte Quellen</li><li>Klare Ausschlüsse</li></ul></aside>
                    </div>
                    <label class="cs-monitoring"><input type="checkbox" wire:model="monitoringRequired"><span><strong>Mitbewerber-Monitoring</strong><p>Diese Websites und Accounts sollen bei der Recherche berücksichtigt werden.</p></span></label>
                    <div class="cs-competitors">
                        @forelse ($competitors as $index => $competitor)
                            <div wire:key="competitor-{{ $index }}" class="cs-competitor">
                                <div class="cs-field"><label for="name-{{ $index }}">Mitbewerber</label><input id="name-{{ $index }}" wire:model="competitors.{{ $index }}.name" placeholder="Unternehmensname" required>@error('competitors.'.$index.'.name') <p class="cs-error" role="alert">{{ $message }}</p> @enderror</div>
                                <div class="cs-field"><label for="url-{{ $index }}">Website oder Account <span class="cs-hint">(optional)</span></label><input id="url-{{ $index }}" type="url" wire:model="competitors.{{ $index }}.url" placeholder="https://…">@error('competitors.'.$index.'.url') <p class="cs-error" role="alert">{{ $message }}</p> @enderror</div>
                                <button class="cs-button cs-remove" type="button" wire:click="removeCompetitor({{ $index }})" aria-label="Mitbewerber-Eintrag {{ $index + 1 }} entfernen"><x-heroicon-o-trash aria-hidden="true" /><span>Entfernen</span></button>
                            </div>
                        @empty
                            <div class="cs-empty"><strong>Noch keine Mitbewerber hinterlegt</strong><p>Füge einen Namen oder direkt einen Website-/Account-Link hinzu.</p></div>
                        @endforelse
                    </div>
                    <div><button class="cs-button" type="button" wire:click="addCompetitor" wire:loading.attr="disabled"><x-heroicon-o-plus aria-hidden="true" />Mitbewerber / Account hinzufügen</button></div>
                    <p class="cs-hint">Mehrere Accounts? Lege je Link einen Eintrag an. Die Links werden hier nur gespeichert, nicht automatisch abgerufen.</p>
                    @error('competitors') <p class="cs-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </section>
            <footer class="cs-savebar"><p>Speichern aktiviert noch keinen Agenten oder Zeitplan. Aktiviere die Verbindung unter „Langdock Verbindung“. Neue Aufträge verwenden das gespeicherte Briefing. Bitte keine Passwörter oder Tokens eintragen.</p><button class="cs-button cs-button-primary" type="submit" wire:loading.attr="disabled"><span wire:loading.remove wire:target="save">Setup speichern</span><span wire:loading wire:target="save">Wird gespeichert …</span></button></footer>
        </form>
    </div>
</x-filament-panels::page>
