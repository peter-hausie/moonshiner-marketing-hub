# Arbeitsauftrag für den nächsten Coding Agent

## Ausgangspunkt

Dies ist ein Laravel/Filament Marketing Hub, kein Lernsystem. Lies README, ARCHITECTURE und AGENTS-INTEGRATION vor Änderungen. Die komplette Originalproduktion ist bewusst nicht enthalten. Keine Annahmen über vorhandene Konten, Domains oder Secrets treffen. Der eingebaute Langdock Connector ist implementiert; nicht nochmals bauen.

## Reihenfolge

1. Frischen Klon installieren, Migrationen und Tests ausführen. Admin interaktiv erstellen. Login, Kalender, Themen und Agenten Setup öffnen.
2. Moonshiner bestätigt Zweck, Zielgruppen, Tonalität, Kanäle und Recherchequellen; diese in Agenten Setup pflegen, nicht im Systemprompt hart verdrahten.
3. Langdock Agenten bereitstellen, Schlüssel und Agenten IDs in Langdock Verbindung konfigurieren. Tonalität und Recherchebriefing im Agenten Setup pflegen.
4. Scheduler und Queue Worker überwachen. Synthetischen Verbindungstest und vollständigen Textauftrag durchspielen.
5. Vorschlag im Beitragsverlauf prüfen und übernehmen. Menschliche Freigabe und Publication bleiben getrennt. Paralleländerungen, Rechteentzug und Wiederholungen testen.
6. Recherche manuell auslösen und Quellen kontrollieren. Eine periodische Recherche ist nicht eingerichtet. Eine spätere Automatik braucht explizite Frequenz, Kostenlimit und Abschaltmöglichkeit.
7. Erst danach Kanalpublisher anschließen. Ein Kalendertermin oder Agentenergebnis darf keine Freigabe ersetzen.
8. Stage mit eigenen Daten und Testzielen abnehmen, Backuprestore prüfen, dann Produktionsdeployment.

## Fertig bedeutet

Ein Mensch speichert Feedback, sieht wartend/laufend/fehlgeschlagen/fertig, erhält genau einen Entwurf und kann ihn prüfen. Ein paralleler menschlicher Edit bleibt erhalten. Wiederholungen erzeugen keine Duplikate. Abgeschaltete Agenten senden nichts. Zugangsdaten und vollständige Providerantworten werden nicht geloggt. Die Anleitung und Übergabeseite spiegeln den tatsächlichen Stand wider.

## Nicht tun

Keine Geheimnisse einchecken, keine Kundenbestände importieren, keine Datenbankmigration zum Branding neuerlich schreiben, keine alten internen Protokollidentitäten ohne Migration umbenennen, keine ungeprüfte Modellantwort direkt in Publication übernehmen. Frameworkcode und generierte Assets nicht mit redundanten Kommentaren überfrachten.
