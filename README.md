# UniFi Access Manager

Zentrale Web-Anwendung zur Verwaltung von Personen, RFID-Karten, Zutrittsgruppen, Türen und Standorten über mehrere **UniFi Access** Controller hinweg – über die offizielle, lokale UniFi API.

## Überblick

- **Personenverwaltung** – Stammdaten, E-Mail, Personalnummer, Status, PIN, Zutrittsgruppen und RFID-Karten je Person.
- **Kartenverwaltung** – RFID-Karten auflisten, filtern, zuordnen und freigeben.
- **Zutrittsgruppen** – UniFi *Access Policies* mit Tür-Zuordnung anlegen, bearbeiten und löschen.
- **Türen** – Türliste pro Standort mit Fernöffnung (*unlock*).
- **Standorte** – ein UniFi-Controller je Standort, inkl. Verbindungstest.
- **Synchronisation** – periodischer Abgleich der lokalen Datenbank mit den Controllern.
- **Audit-Log** – vollständige, revisionssichere Nachverfolgung aller Änderungen.
- **Benutzer & Rollen** – lokale Anmeldung mit den Rollen *Administrator*, *Operator*, *Nur Lesen*.
- **CSV-Export** – Personen, Karten und Audit-Log exportierbar.

## Technologie

| Bereich      | Technologie                                   |
|--------------|-----------------------------------------------|
| Backend      | PHP 8.3 (ohne Framework)                      |
| Frontend     | Vanilla JS, kein externes CDN                 |
| Datenbank    | MariaDB / MySQL via PDO                       |
| Betrieb      | Docker / Docker Compose, Nginx, PHP-FPM       |
| Abhängigkeiten | keine Composer-/npm-Pakete                   |

## Schnellstart

```bash
# 1. Umgebung konfigurieren
cp .env.example .env
#    APP_SECRET unbedingt auf einen langen Zufallswert setzen
#    UNIFI_API_MOCK=true lässt die App gegen den eingebauten Mock laufen

# 2. Stack bauen und starten
docker compose up -d --build

# 3. Datenbank migrieren und Demo-Daten anlegen
docker compose run --rm app php bin/cli.php migrate
docker compose run --rm app php bin/cli.php seed

# 4. Öffnen und anmelden
#    http://localhost:8080
#    Benutzer: admin / admin@example.com / admin1234
```

> Der Seeder legt in der Standardkonfiguration einen Administrator
> (`ADMIN_USERNAME` / `ADMIN_EMAIL` / `ADMIN_PASSWORD`) an und erzeugt im
> Mock-Modus einen „Demo Standort“ inkl. synchronisierter Beispieldaten.

## Verzeichnisstruktur

```
app/
  Api/            UniFi-API-Abstraktion (real + Mock)
  Auth/           Rate-Limiting für Login
  Config/         .env-/Umgebungsvariablen-Konfiguration
  Controllers/    Web-, API- und Export-Controller
  Core/           Router, Request/Response, View, Session, Logger, App
  Helpers/        Ansichts-Helfer (e(), json_attr(), format_date(), …)
  Repositories/   PDO-Datenzugriff
  Security/       Auth, CSRF, Crypto (AES-256-GCM)
  Services/       Fachlogik
  Views/          Layouts, Seiten und Partials
bin/cli.php       migrate, seed, sync, create-admin
database/         SQL-Migrationen
docker/           Nginx-Konfiguration
public/           Front-Controller, CSS, JS
tests/run.php     Dependency-freie Testsuite
```

## Konfiguration

Alle Einstellungen werden über `.env` bzw. Umgebungsvariablen gesteuert. Eine
vollständige Referenz steht in `.env.example`. Wichtig:

| Variable          | Bedeutung                                                        |
|-------------------|------------------------------------------------------------------|
| `APP_SECRET`      | Pflicht. Schlüssel für Session-Signierung und Token-Verschlüsselung. |
| `UNIFI_API_MOCK`  | `true` = Mock-API, `false` = echte UniFi-Controller.             |
| `DB_*`            | Datenbankverbindung (Standardwerte passen zum Compose-Stack).    |
| `ADMIN_*`         | Initialer Admin-Account (nur beim Seeden verwendet).             |

## Dokumentation

- [Architektur](docs/architecture.md)
- [UniFi API](docs/unifi-api.md)
- [Datenbank](docs/database.md)
- [Deployment](docs/deployment.md)
- [Sicherheit](docs/security.md)
- [Interne API](docs/api.md)

## Tests

```bash
docker compose run --rm \
  -v "${PWD}/tests:/var/www/html/tests" \
  app php tests/run.php
```

Die Suite benötigt eine migrierte und (im Mock-Modus) geseedete Datenbank.

## Hinweise

- Die Anwendung kommuniziert ausschließlich mit der **lokalen** UniFi-API
  (`https://<host>:12445`). Eine Cloud-API wird nicht verwendet.
- API-Tokens werden mit AES-256-GCM verschlüsselt in der Datenbank gespeichert.