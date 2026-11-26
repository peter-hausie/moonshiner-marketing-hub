# Installation, Betrieb und Wiederherstellung

## Lokaler Start

Die Befehle im README verwenden eine leere SQLite Datenbank. `hub:create-admin` fragt Name, E Mail und Passwort verdeckt ab und überschreibt keine vorhandenen Konten. Danach weitere Benutzer über Accounts einladen. `DatabaseSeeder` erstellt bewusst keinen Standardnutzer.

## Server

PHP 8.4 mit den von Composer verlangten Erweiterungen, Composer 2, Node 22.12+, TLS und MySQL/MariaDB bereitstellen. Document Root ausschließlich `public/`. Schreibrechte nur für `storage/` und `bootstrap/cache/`; `.env` nicht öffentlich lesbar. Stage und Prod strikt getrennt betreiben, einschließlich APP_KEY, Datenbank, Mail, Queue, Medien und Agentenidentitäten.

`APP_ENV=production`, `APP_DEBUG=false`, korrekte HTTPS APP_URL, sichere Session Cookies und SMTP über geschützte Hostkonfiguration setzen. APP_KEY einmalig erzeugen und geschützt sichern. Nicht bei jedem Deployment erneuern. `.env.example` enthält keine produktiven Zugangsdaten. Domainnamen im Code sind Beispielwerte.

Nach Backup: `composer install --no-dev --optimize-autoloader`, Frontendbuild erzeugen, `php artisan migrate --force`, `php artisan optimize`, Worker kontrolliert neu starten. Keine fremde Datenbank oder Bestandsmedien in einen Klon kopieren.

## Hintergrundarbeit

Für freigegebene Veröffentlichungen: ein überwachter Laravel Queue Worker und `php artisan schedule:run` jede Minute. Vor Aktivierung Publisher in einer isolierten Umgebung prüfen. In `staging` ist Publishing unterdrückt. Der Scheduler startet KEINEN externen Schreibagenten; dafür ist der Connector aus AGENTS-INTEGRATION erforderlich.

## Kanäle

Blogkonfiguration: MOONSHINER_BLOG_API_URL, MOONSHINER_BLOG_API_TOKEN und optional Basic Auth. Geheimnisse ausschließlich im Host Secret Store. Medienlieferung ist absichtlich standardmäßig deaktiviert, bis der Zielimporter den unveränderlichen Medientransport nachweislich unterstützt. Kanalwahl in der Oberfläche bedeutet nicht, dass für jedes soziale Netzwerk ein Transport vorhanden ist. LinkedIn und weitere Adapter sind separat zu prüfen/implementieren.

## Sicherung und Rollback

Aktive DB Verbindung zuerst ermitteln. Einen konsistenten Dump dieser Datenbank, private Medien und APP_KEY sichern, nicht eine eventuell ungenutzte SQLite Datei. Restore in isolierter Umgebung testen. Nach Restore Agentenzugänge quarantänisieren, keine alten Tokens blind reaktivieren. Migrationen können historischen Datenbestand schützen und Rollback verweigern; niemals zur Umgehung Tabellen löschen. Codeversion und Schema getrennt dokumentieren.

## Abnahme vor Betrieb

Login, Einladung mit Testmail, Sperren bestehender Sessions, Kalenderänderung, Themenübernahme, Versionskonflikt, Ergebniswiederholung, Tokenwiderruf und fehlende Freigabe prüfen. MySQL Konkurrenztests sind zusätzlich zu SQLite Tests erforderlich. Logs dürfen weder Tokens noch vollständige Anfragen mit vertraulichem Inhalt enthalten.
