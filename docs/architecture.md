# Architektur

## Überblick

Die Anwendung ist eine klassische, serverseitig gerenderte Web-App nach dem
**MVC-ähnlichen** Muster – ohne Framework, mit expliziter Verdrahtung:

```
Browser
   │  HTTP
   ▼
Nginx (public/, statische Assets, Reverse-Proxy zu PHP-FPM)
   │  FastCGI
   ▼
public/index.php ── Front-Controller / Router
   │
   ├─ Controllers/Web     (PageController, AuthController, ExportController)
   ├─ Controllers/Api     (JSON-Endpunkte)
   ├─ Services            (Fachlogik)
   ├─ Repositories        (PDO-Datenzugriff)
   ├─ Api/                (UniFiApiClient / MockUniFiApiClient)
   │        │  HTTPS (12445)
   │        ▼
   │   UniFi Access Controller (lokal)
   ▼
MariaDB (Cache + Stammdaten)
```

## Schichten

| Schicht        | Verantwortung                                                   |
|----------------|-----------------------------------------------------------------|
| `public/`      | Front-Controller, statische Assets (CSS/JS), Einstiegspunkt     |
| `Controllers`  | HTTP-Verarbeitung, Autorisierung, Validierung, Response-Format   |
| `Services`     | Fachlogik, orchestriert Repositories + UniFi-Client + Audit      |
| `Repositories` | SQL-Zugriff auf die lokale Datenbank (PDO, Prepared Statements)  |
| `Api`          | Abstraktion der UniFi-API; Factory wählt real/mock               |
| `Core`         | Router, Request/Response, View, Session, Logger, App-Locator     |
| `Security`     | Auth, CSRF, Crypto (AES-256-GCM)                                 |

## Datenhaltung: Cache-Modell

Die UniFi-Controller sind die **Source of Truth**. Die lokale Datenbank hält
einen lesbaren **Cache** (Tabellen `unifi_users`, `unifi_credentials`,
`unifi_access_groups`, `unifi_doors`). Jede Mutation wird zunächst an die
UniFi-API gesendet, anschließend wird der betroffene Datensatz im Cache
aktualisiert. Die periodische Synchronisation ersetzt den Cache eines
Standorts vollständig (Replace-Strategie).

## Routing

`public/index.php` definiert die gesamte Routentabelle:

- **Web-Routen** rendern HTML-Seiten über `View::render()`.
- **API-Routen** antworten mit JSON (`{success, data}` bzw.
  `{success:false, error:{code,message}}`).
- **Export-Routen** liefern CSV-Downloads.

Der Router unterstützt benannte Platzhalter (`/persons/{connection_id}/{unifi_id}`)
sowie Method-Override (`_method` bzw. `X-HTTP-Method-Override`).

## View-Rendering

`View::render($template, $data, $layout)` rendert zuerst das Template in einen
String und bettet diesen anschließend in ein Layout ein (`app` mit Sidebar
oder `auth` für die Login-Seite). Partials (z. B. Pagination) werden über
`View::partial()` gerendert.

## Dependency-Injection / Service-Locator

`App` (`app/Core/App.php`) ist ein minimaler Service-Locator: Jeder Service
wird einmalig konstruiert und seine Abhängigkeiten explizit verdrahtet. Es
gibt keine Reflection-Autowiring.

## Fehlerbehandlung

- `public/index.php` fängt alle `Throwable` ab und liefert je nach Route eine
  JSON-Fehlerantwort (`/api/*`) oder eine generische HTML-Fehlerseite.
- API-Controller wrappen Aufrufe in `ApiController::run()`, das
  `UniFiApiException` → 400, `InvalidArgumentException` → 422 und alle
  übrigen Fehler → 500 übersetzt.
- Logging erfolgt über `App\Core\Logger` nach `storage/logs/app.log` mit
  automatischer Maskierung von Secrets.
