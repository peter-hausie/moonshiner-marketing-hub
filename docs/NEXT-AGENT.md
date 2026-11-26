# Arbeitsauftrag für den nächsten Coding Agent

## Ausgangspunkt

Dies ist ein Laravel/Filament Marketing Hub, kein Lernsystem. Lies README, ARCHITECTURE und AGENTS-INTEGRATION vor Änderungen. Die komplette Originalproduktion ist bewusst nicht enthalten. Keine Annahmen über vorhandene Konten, Domains oder Secrets treffen.

## Reihenfolge

1. Frischen Klon installieren, Migrationen und Tests ausführen. Admin interaktiv erstellen. Login, Kalender, Themen und Agenten Setup öffnen.
2. Moonshiner bestätigt Zweck, Zielgruppen, Tonalität, Kanäle und Recherchequellen; diese in Agenten Setup pflegen, nicht im Systemprompt hart verdrahten.
3. Einen Anbieter auswählen, aktuelle API Dokumentation prüfen. Langdock ist eine Option, keine bestehende Integration. Pro Rolle getrennte Konfiguration und minimale Rechte.
4. Connector gemäß AGENTS-INTEGRATION implementieren. Auftragsabholung, Lease/Timeout, Idempotenz, Konfliktstatus und sichtbare Fehler gehören zum selben vertikalen Ablauf.
5. Prüfe die Speicherung und Übernahme von Vorschlagsversionen in der UI. Ein erfolgreiches API Resultat allein ist noch kein für die Redaktion benutzbarer Workflow.
6. Automatische Recherche erst nach funktionierender manueller Auslösung ergänzen. Frequenz, Quellenlimits, Kostenlimit und Abschaltmöglichkeit festlegen. Kein stiller Dauerlauf.
7. Erst danach Kanalpublisher anschließen. Ein Kalendertermin oder Agentenergebnis darf keine Freigabe ersetzen.
8. Stage mit eigenen Daten und Testzielen abnehmen, Backuprestore prüfen, dann Produktionsdeployment.

## Fertig bedeutet

Ein Mensch speichert Feedback, sieht wartend/laufend/fehlgeschlagen/fertig, erhält genau einen Entwurf und kann ihn prüfen. Ein paralleler menschlicher Edit bleibt erhalten. Wiederholungen erzeugen keine Duplikate. Abgeschaltete Agenten senden nichts. Zugangsdaten und vollständige Providerantworten werden nicht geloggt. Die Anleitung und Übergabeseite spiegeln den tatsächlichen Stand wider.

## Nicht tun

Keine Geheimnisse einchecken, keine Kundenbestände importieren, keine Datenbankmigration zum Branding neuerlich schreiben, keine alten internen Protokollidentitäten ohne Migration umbenennen, keine ungeprüfte Modellantwort direkt in Publication übernehmen. Frameworkcode und generierte Assets nicht mit redundanten Kommentaren überfrachten.
