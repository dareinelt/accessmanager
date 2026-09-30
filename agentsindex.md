# UniFi Access Manager – Agents Index

Kurzer Überblick für Coding Agents: Architektur, Datei-Layout, zentrale
Muster und die wichtigsten Einstiegspunkte, damit du dich ohne komplettes
Repo-Studium orientieren kannst.

## 1. Was die Anwendung ist

Zentrale Verwaltungsoberfläche für UniFi Access-Controller. Die App bündelt
Personen, RFID-Karten (Credentials), Zutrittsgruppen, Türen und Standorte
(Verbindungen zu UniFi-Controllern) in einer einzigen Web-UI und hält einen
lokalen, synchronisierten Katalog als Cache vor.

## 2. Technische Eckdaten & harte Constraints

- PHP 8.x, **kein Framework** (eigener Autoloader, Router, View-Renderer).
- MySQL/MariaDB über **PDO** (kein ORM; handgeschriebenes SQL in Repositories).
- Frontend: **Vanilla JS + eigenes CSS**, **keine CDN/JS-Libs** (`public/assets/`).
- Docker Compose-Stack (`docker-compose.yml`) mit 4 Services: `app`, `web`,
  `db`, `cron`.
- **HTTPS ist standardmäßig aktiv** (Selbstsigniertes Fallback-Zertifikat über
  einen CSR → Signieren → Import → Aktivieren-Workflow, portiert aus
  `dareinelt/lanpa`).

## 3. Verzeichnis-Layout

```
app/
  Api/              UniFi-API-Client (Interface, echtes Client, Mock-Client, Factory)
  Auth/             RateLimiter (Login-Brute-Force-Schutz)
  bootstrap.php     Autoloading, Config, Session-Start, Error-Handling
  Config/           Config (liest .env)
  Controllers/      Web-Controller + Unterordner Controllers/Api (JSON-Endpunkte)
  Core/             App (Service-Locator), Database, Request, Response, Router,
                    Session, View, Logger
  Helpers/          Globale Helper (helpers.php)
  Repositories/     Datenbankzugriff (PDO, handgeschriebenes SQL)
  Security/         Auth (Login/Rollen), Crypto (AES-256-GCM), Csrf
  Services/         Fachlogik (Controller → Service → Repository)
  Services/Tls/     Zertifikats-Workflow (TlsCertificateService, CertificateInspector)
  Views/            PHP-Templates (layouts/, pages/, partials/, auth/)
bin/cli.php         CLI-Runner (migrate, seed, sync, create-admin, tls:sync, tls:state)
database/
  migrations/       001_initial.sql, 002_tls_certificates.sql
  seeds/            Seeder (Mock-Demo-Daten, Admin-Konto)
docker/             nginx.conf, app-entrypoint.sh, web-entrypoint.sh
public/
  index.php         Front-Controller + Routentabelle
  assets/           css/, js/, images/ (keine externen Libs)
storage/            logs/, cache/, tls/ (Laufzeit, via Named Volume geteilt)
tests/run.php       Dependency-freier Test-Runner
tmp/                Transiente Artefakte (nicht committen)
.env / .env.example Umgebungsvariablen
Dockerfile          Baut das app-Image (Entrypoint: migrate → tls:sync → php-fpm)
.gitattributes      Erzwingt LF für *.sh/*.php/*.conf/*.sql (Windows-CRLF-Falle)
```

## 4. Request-Lifecycle

```
Browser
  └─> public/index.php (Front-Controller)
        ├─ app/bootstrap.php (Autoload, .env laden, Session starten)
        └─ Router::dispatch(Request)
              └─ Controller-Methode ($controller->method($request, $params))
                    ├─ Auth::requireRole('admin', ...)  // Zugriffsschutz
                    ├─ Csrf::validate(...)               // bei POST/PUT/DELETE
                    ├─ App::<service>()                  // Service-Locator
                    │     └─ Repository (PDO)            // Datenzugriff
                    └─ $this->view('pages/...', $data)   // HTML rendern
                          oder Response::json(...)       // API-Antwort
```

- Routen werden ausschließlich in `public/index.php` definiert. `{param}`
  wird per Regex aufgelöst; Methoden-Override via `_method`-Feld oder
  `x-http-method-override`-Header.
- Web-Routen rendern Views; `/api/*`-Routen liefern JSON.

## 5. Zentrale Muster

- **Service-Locator `App`** (`app/Core/App.php`): Services werden faul und
  einmalig konstruiert, Abhängigkeiten explizit verdrahtet
  (`App::persons()`, `App::groups()`, `App::doors()`, `App::sites()`,
  `App::sync()`, `App::auth()`, `App::audit()`, `App::tls()`, …).
- **Controller → Service → Repository**: Controller handhaben HTTP/CSRF/Rollen,
  Services die Fachlogik, Repositories das SQL.
- **PDO**: `Database::connection()` liefert die gemeinsame Verbindung.
  Wichtig: `PDO::ATTR_EMULATE_PREPARES => false` → benannte Platzhalter dürfen
  pro Statement **nicht** mehrfach verwendet werden (sonst HY093).
- **Views** (`app/Views/`): PHP-Templates mit `@var`-Annotationen für
  übergebene Daten; Layouts unter `layouts/app.php` (angemeldet) und
  `layouts/auth.php` (Login).

## 6. Sicherheitsmodell

- **Rollen**: `admin`, `operator`, `readonly` (`App\Security\Auth`).
  `Auth::requireRole(...)` bzw. `Auth::requireLogin()` am Controller-Einstieg.
- **CSRF**: `Csrf::token()` / `Csrf::validate()` (Vergleich via `hash_equals`).
  Formulare tragen das Feld `_csrf`.
- **Session**: `Session` setzt HttpOnly/SameSite=Lax-Cookies; `secure` wird nur
  gesetzt, wenn `isSecureRequest()` greift → nginx muss `fastcgi_param HTTPS on`
  bzw. `X-Forwarded-Proto` liefern.
- **Verschlüsselung**: `Crypto` (AES-256-GCM) für UniFi-API-Tokens und
  TLS-Private-Keys; `APP_SECRET` ist Pflicht.
- **Rate-Limiting**: `App\Auth\RateLimiter` schützt den Login.

## 7. Datenbank & Migrationen

- Schema ausschließlich über `database/migrations/*.sql` (Nummernpräfix).
- `bin/cli.php migrate` wendet alle an; `seed` legt Admin + Mock-Daten an.
- `tls_certificates` speichert CSR, verschlüsselten Private-Key,
  Zertifikat + Kette, `public_key_hash` (SHA-256 des normalisierten
  Public-Key-PEM, Zuordnung CSR ↔ Zertifikat), Aktiv-/Fallback-Status.

## 8. Docker & HTTPS-Zertifikats-Workflow

- `docker-compose.yml`: `web` mapped `8080:80` (HTTP, leitet 301 auf
  `https://$host:8443`) und `8443:443` (HTTPS). `app_storage` (Named Volume)
  teilt `storage/tls` zwischen `app`/`web`/`cron`.
- `app-entrypoint.sh`: `migrate` → `tls:sync` → `exec php-fpm`.
- `web-entrypoint.sh`: wartet auf `storage/tls/live.crt`/`live.key`, lädt nginx
  bei Änderung neu (md5sum-Watcher).
- **Ablauf**: CSR erzeugen → von CA signieren lassen → Zertifikat importieren
  (Vorschau mit Warnungen) → bestätigen/aktivieren. Ohne aktives Zertifikat
  generiert `ensureFallback()` ein selbstsigniertes „Notfall-Zertifikat“.
  `live()` liefert je nach Zustand `mode: strict` (CSR-signiert) oder
  `mode: fallback` (selbstsigniert).

## 9. Befehle, Tests, Linting

```bash
# Lint (lokales PHP ist hier ggf. defekt → im Container prüfen):
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli-alpine sh -c \
  'find app bin public -name "*.php" -print0 | xargs -0 -n1 php -l'

# Tests (tests/ ist per .dockerignore aus dem Image ausgeschlossen,
# daher bind-mounten):
docker compose run --rm -v "${PWD}/tests:/var/www/html/tests" app php tests/run.php

# CLI:
php bin/cli.php migrate
php bin/cli.php seed
php bin/cli.php sync
php bin/cli.php create-admin <username> <email> <password> [role]
php bin/cli.php tls:sync
php bin/cli.php tls:state
```

## 10. Umgebungsvariablen (`.env`)

| Variable | Bedeutung |
|----------|-----------|
| `APP_URL` | Basis-URL, standardmäßig `https://localhost:8443` (liefert Host für TLS) |
| `APP_SECRET` | Pflicht; Schlüssel für Session-Signierung + Verschlüsselung |
| `APP_ENV`, `APP_DEBUG` | `production` / `false` |
| `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD` | MariaDB-Zugriff |
| `SESSION_LIFETIME`, `SESSION_NAME` | Session-Cookie-Konfiguration |
| `UNIFI_API_MOCK` | `true` → Mock-API statt echtem Controller (Dev/Tests) |
| `ADMIN_USERNAME/EMAIL/PASSWORD` | nur vom Seeder verwendet |

## 11. Bekannte Eigenheiten / Fallstricke

- **Keine externen Frontend-Libs**: JS/CSS nur in `public/assets/`; nichts von
  CDN laden.
- **Windows-CRLF**: In Windows erzeugte `*.sh`-Dateien brechen Entrypoint-Skripte
  im Linux-Container (`exec …: no such file or directory`). `.gitattributes`
  erzwingt LF; neue Shell-Skripte immer mit LF committen.
- `Response::redirect()` und `Response::error()` sind `never` — kein Code danach.
- `Config::bool()`/`Config::int()` für typisierte Env-Werte verwenden.
- Mock-Modus (`UNIFI_API_MOCK=true`) seedet „Demo Standort“ mit `verify_ssl=false`.
- API-Client-Factory (`ApiClientFactory`) entscheidet anhand der Config zwischen
  echtem Client und Mock-Client; beides implementiert `UniFiApiClientInterface`.
