<x-filament-panels::page>
    <p>Einrichtung, Architektur und nächste Entwicklungsschritte. Agentenconnector noch nicht aktiviert.</p>
    @foreach ($this->documents() as $name => $html)
        <x-filament::section :heading="$name" collapsible :collapsed="$name !== 'HANDOVER'">
            <div class="handover-document">{!! $html !!}</div>
        </x-filament::section>
    @endforeach
    <style>
        .handover-document { max-width: 80ch; line-height: 1.65; overflow-wrap: anywhere; }
        .handover-document h1, .handover-document h2 { font-weight: 650; margin: 1.3em 0 .5em; }
        .handover-document h1 { font-size: 1.35rem; }
        .handover-document h2 { font-size: 1.1rem; }
        .handover-document p, .handover-document ul, .handover-document ol { margin-bottom: .85em; }
        .handover-document ul { list-style: disc; padding-left: 1.4em; }
        .handover-document ol { list-style: decimal; padding-left: 1.4em; }
        .handover-document pre { overflow-x: auto; padding: 1em; background: rgba(120,120,120,.1); }
        .handover-document a { text-decoration: underline; }
    </style>
</x-filament-panels::page>
