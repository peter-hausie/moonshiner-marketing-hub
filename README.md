# Moonshiner Marketing Hub

Interne Redaktion für Themenrecherche, Contentplanung, Ausarbeitung, Freigabe und kanalbezogene Veröffentlichung. Dies ist ein eigenständiger Quellcodeexport ohne Kundendaten, Konten, Zugangsdaten oder alte Deploymenthistorie.

## Schnellstart

PHP 8.4+, Composer 2, Node 22.12+ und npm. SQLite für lokal; MySQL/MariaDB für einen getrennten Produktivbetrieb.

```sh
composer install
cp .env.example .env
php artisan key:generate
php -r "touch('database/database.sqlite');"
php artisan migrate
php artisan hub:create-admin
npm install --ignore-scripts
npm run build
php artisan serve
```

Öffne http://127.0.0.1:8000/admin. Der neue Administrator wird interaktiv erstellt; kein Standardpasswort. Unter **Technische Übergabe** ist die Anleitung auch im Hub lesbar. Mail wird lokal nur protokolliert. Agenten APIs bleiben standardmäßig aus.

## Zuerst lesen

1. [Übergabe und Zweck](docs/HANDOVER.md)
2. [Einrichtung und Betrieb](docs/OPERATIONS.md)
3. [Agenten und Connector](docs/AGENTS-INTEGRATION.md)
4. [Nächste Implementierungsschritte](docs/NEXT-AGENT.md)
5. [Architektur und Codepfade](docs/ARCHITECTURE.md)

## Liefergrenze

Redaktion, Kalender, Rechte, Einladungen, Versionen und Feedback sind implementiert. Der Langdock Connector verarbeitet neue Textaufträge und manuell ausgelöste Recherche. Backendkonfiguration unter **Langdock Verbindung**: API Schlüssel, Agenten IDs, Aktivierung und Verbindungstest. Scheduler und Queue Worker müssen auf dem Host laufen; siehe [Einrichtung](docs/AGENTS-INTEGRATION.md). Kein echter Anbieterzugang ist enthalten oder aktiviert.

```sh
php artisan test
vendor/bin/pint --test
```

Vor Produktivbetrieb sind SMTP, Datenbank, Host, Worker, Sicherungen und Anbieterzugänge installationsspezifisch einzurichten. Die `.example` Adressen sind ausdrücklich keine erreichbaren Dienste.
