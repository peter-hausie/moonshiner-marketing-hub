# Technische Übergabe: Moonshiner Marketing Hub

## Zweck

Eine Redaktion soll aus belegten Themenideen nachvollziehbare Beiträge machen. Recherche schlägt Themen vor, Menschen wählen sie aus, ein Textagent entwirft Varianten, Menschen geben den konkreten Stand zur Veröffentlichung frei. Kalender und Verantwortlichkeiten koordinieren die Arbeit. Ein Agent darf nie selbst veröffentlichen oder menschliche Freigaben vergeben.

## Bedienung

1. Administrator mit `php artisan hub:create-admin` anlegen.
2. Accounts einladen und als Administrator, Redaktionsleitung oder Autor berechtigen.
3. In Agenten Setup Zielgruppe, Tonalität, Quellenrahmen und gewünschte Mitbewerber speichern.
4. Themenvorschlag übernehmen. Erneutes Übernehmen öffnet denselben Beitrag.
5. Beitrag bearbeiten, Verantwortlichen und Kanäle setzen, Feedback im Textagentenbereich speichern.
6. Kalenderdatum per Drag and Drop oder Formular wählen. Ein Termin ist keine Freigabe.
7. Konkrete Version prüfen und an die Veröffentlichung binden. Erst dann einen konfigurierten Publisher verwenden.

## Was funktioniert und was noch fehlt

Die redaktionelle Oberfläche und gespeicherte Briefings sind vorhanden. Feedback ist derzeit `awaiting_connection`: es wird dauerhaft mit Kontext gespeichert, aber nicht automatisch an einen Anbieter gesendet. Die Agenten API kann registrierte Versuche annehmen; sie erstellt keine Jobs auf Zuruf eines Modells. Der vertrauenswürdige Connector zwischen Oberfläche, Jobverwaltung und Anbieter fehlt noch. Das ist der erste Entwicklungsauftrag nach der Installation.

## Dokumentation im Repository

`OPERATIONS.md` erklärt den Betrieb. `AGENTS-INTEGRATION.md` beschreibt Rollen und API. `NEXT-AGENT.md` ist die konkrete Arbeitsliste. `ARCHITECTURE.md` erklärt Zuständigkeiten und Schutzmechanismen. Keine Altserver oder alten Konten wiederverwenden. Jede Installation bekommt neue Schlüssel, Datenbank und Zugangsdaten.
