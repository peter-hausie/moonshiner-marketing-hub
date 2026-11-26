# Lieferstand

## Enthalten

Eigenständiger Moonshiner Quellcode ohne ursprüngliche Git Historie. Branding, Blog Service Schlüssel und Umgebungsvariablen konsistent umbenannt. Personenbezogene automatische Rollenvergabe aus der Initialmigration entfernt. Keine Standardkonten im Seeder. Interaktiver Befehl `hub:create-admin`. Geschützte Filament Seite Technische Übergabe mit den fünf Handbüchern. Kommentare an zentralen Workflowgrenzen, technische Leseführung und nächste konkrete Arbeitsliste.

## Geprüft am 26. November 2026

* Composer Installation aus Lockdatei erfolgreich, PHP 8.4.
* npm Installation und Vite Produktionsbuild erfolgreich; npm meldet null Schwachstellen zum Prüfzeitpunkt.
* Gesamte PHP Suite: 297 Tests, 2122 Assertions bestanden.
* Neue Tests prüfen Dokumentationsrechte, gerenderte Anleitung sowie Adminrechte und Passwort Hashing.
* Exportdateien auf alte Marken/Hostnamen, private Schlüssel und übliche Credentialmuster geprüft. Keine entsprechenden Geheimnisfunde. Eine zufällige Zeichenfolge in einer npm Integritätsprüfsumme wurde nicht verändert.
* Keine Datenbanken, Laufzeitlogs, .env Dateien, Uploads, Kundeninhalte oder ursprüngliche Deploymentskripte im Repository.
* Originalprojekt unverändert. Kein Produktivdeployment, kein echter Mailversand, keine Anbieteraktivierung.

## Noch nicht behauptet

Kein vollständiger externer Security Audit. Keine reale Langdock Anbindung, kein realer Providerlauf und kein neuer MySQL Konkurrenztest. Kein persönlicher Browserlogin der Empfänger. Der CI Workflow ist vorbereitet, sein GitHub Lauf steht bis zur Veröffentlichung aus.

## Öffentliche Veröffentlichung

Repository Name: moonshiner-marketing-hub. Öffentliche Veröffentlichung ist vom Auftraggeber gewünscht; der korrekte GitHub Eigentümer muss eindeutig zugeordnet sein. Diese Lieferung enthält nur den Code, nicht das Recht zur Weitergabe fremder Kundendaten. Abhängigkeiten behalten ihre eigenen Lizenzen.
