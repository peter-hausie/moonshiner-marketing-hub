# Lieferstand

## Enthalten

Eigenständiger Moonshiner Quellcode ohne ursprüngliche Git Historie. Branding, Blog Service Schlüssel und Umgebungsvariablen konsistent umbenannt. Personenbezogene automatische Rollenvergabe aus der Initialmigration entfernt. Keine Standardkonten im Seeder. Interaktiver Befehl `hub:create-admin`. Geschützte Filament Seite Technische Übergabe mit den fünf Handbüchern. Kommentare an zentralen Workflowgrenzen, technische Leseführung und nächste konkrete Arbeitsliste.

## Geprüft am 26. November 2026

* Composer Installation aus Lockdatei erfolgreich, PHP 8.4.
* npm Installation und Vite Produktionsbuild erfolgreich; npm meldet null Schwachstellen zum Prüfzeitpunkt.
* Gesamte PHP Suite: 312 Tests, 2182 Assertions bestanden.
* Neue Tests prüfen Dokumentationsrechte, gerenderte Anleitung sowie Adminrechte und Passwort Hashing.
* Exportdateien auf alte Marken/Hostnamen, private Schlüssel und übliche Credentialmuster geprüft. Keine entsprechenden Geheimnisfunde. Eine zufällige Zeichenfolge in einer npm Integritätsprüfsumme wurde nicht verändert.
* Keine Datenbanken, Laufzeitlogs, .env Dateien, Uploads, Kundeninhalte oder ursprüngliche Deploymentskripte im Repository.
* Originalprojekt unverändert. Kein Produktivdeployment, kein echter Mailversand, keine Anbieteraktivierung.

## Noch nicht behauptet

Kein vollständiger externer Security Audit. Langdock Adapter implementiert und mit kontrollierten API Antworten geprüft; kein realer Providerlauf und kein neuer MySQL Konkurrenztest. Kein persönlicher Browserlogin der Empfänger. Der CI Workflow läuft auf GitHub bei jedem Push. Der aktuelle Lauf ist im Actions Bereich des Repository sichtbar.

## Öffentliche Veröffentlichung

Öffentliches Repository: https://github.com/peter-hausie/moonshiner-marketing-hub . Veröffentlichung ist vom Auftraggeber gewünscht. Diese Lieferung enthält nur den Code, nicht das Recht zur Weitergabe fremder Kundendaten. Abhängigkeiten behalten ihre eigenen Lizenzen.

## Langdock Connector

Backendkonfiguration mit verschlüsseltem API Schlüssel, getrennten Text und Recherche Agenten, Verbindungstest und Aktivierung. Neue Textaufträge und Feedback werden asynchron verarbeitet. Recherche erzeugt Themenvorschläge; Texte bleiben Vorschläge mit ausdrücklicher menschlicher Übernahme. Rechte, Konfigurationsrevision, Inhaltsversion und doppelte Zustellungen werden geprüft. Fehler und Wiederholungen sind sichtbar. Scheduler und Queue Worker müssen einmalig eingerichtet werden; danach erfolgt die Anbieterkonfiguration im Backend. Vollständige Einrichtung und Testgrenzen stehen in AGENTS-INTEGRATION.md.
