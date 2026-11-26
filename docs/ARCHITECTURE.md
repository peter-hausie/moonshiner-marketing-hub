# Architektur und Leseführung

## Schichten

* `app/Filament`: Oberfläche, Autorisierung bei Aktionen und Formulare. Keine Anbieteraufrufe hier ergänzen.
* `app/Services/Content`: Versionierung, Übernahme von Themen, Nachrichten und Freigabeprüfungen.
* `app/Services/Agents`: validierte Verträge, Auftragsversuche, persistente Berechtigungen und Annahme von Ergebnissen.
* `app/Http`: explizit aktivierbare API Grenzen mit festen Fehlern, Größenlimits und Sanctum Authentifizierung.
* `app/Jobs/PublishPublication.php`: Veröffentlichung mit erneuter Freigabeprüfung zur Ausführungszeit.
* `app/Services/Publishing`: eingefrorener freigegebener Payload, Transport und Medienfähigkeiten.
* `database/migrations`: vollständige additive Schemahistorie; nicht durch einen Datenbankdump ersetzen.
* `tests/Feature`: ausführbare Beispiele für Erfolg, Konflikte, Wiederholung, Rechteentzug und Rollback.

## Datenfluss

ResearchItem → Opportunity → ContentItem → content_versions → Publication → PublicationAttempt.
ContentAgentMessage speichert Briefing/Feedback samt Kontextstand. AgentPrincipal ist keine menschliche Anmeldung. AgentEditorialSetting speichert Konfiguration, niemals Zugangsdaten.

## Warum die zusätzlichen Prüfungen?

Ein Request kann wiederholt werden. Ein Ergebnis kann verspätet kommen. Ein Mensch kann zwischen Kontextabfrage und Rückgabe editieren. Deshalb werden Aufträge an eine Version UND einen Hash gebunden, Versuche nummeriert und Ergebnisse idempotent angenommen. Ein Entwurf erzeugt eine Vorschlagsversion, überschreibt aber nicht automatisch den editierbaren Beitrag. Diese Trennung beim Connector beibehalten.

`journalist` und `underwood` sind stabile interne Protokollnamen, keine öffentlich vorgeschriebenen Agentennamen. Sichtbare Namen sind im Agenten Setup konfigurierbar. Renaming dieser IDs erfordert koordinierte Migration von Scopes und Tests und ist für Branding nicht nötig.

## Kommentierungsprinzip

Kommentare erklären Absicht, Transaktionsgrenzen und Fehlerfälle, nicht jede selbsterklärende Zuweisung. Besonders beachten: RecordAgentMessage (kein Dispatch), DraftContext (Serverhash), AgentJobLifecycle (Versuchsnummer), AcceptDraftSubmission (Vorschlag statt Überschreiben), PublishPublication (Queue ist keine Freigabe).
