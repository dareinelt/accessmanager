# Sicherheit

## Authentifizierung & Session

- Lokale Anmeldung gegen `users` (Passwort-Hash via `password_hash`/`password_verify`).
- Session-Cookie: `HttpOnly`, `SameSite=Lax`, `Secure` bei HTTPS, konfigurierbare
  Lebensdauer (`SESSION_LIFETIME`) und Name (`SESSION_NAME`).
- Session-Regenerierung (`session_regenerate_id(true)`) bei erfolgreichem Login.

## Autorisierung / Rollen

| Rolle       | Rechte                                                              |
|-------------|---------------------------------------------------------------------|
| `admin`     | Vollzugriff inkl. Standorte, Audit, Benutzer, Einstellungen          |
| `operator`  | Personen/Karten/Gruppen/Türen verwalten, Synchronisation ausführen   |
| `readonly`  | Nur Lesen                                                           |

Alle mutierenden API-Routen prüfen die Rolle (`ApiController::authorize()`)
und CSRF (`ApiController::requireCsrf()`). Webseiten prüfen über
`Auth::requireRole()`.

## CSRF

- Token in der Session, eingebettet als `<meta name="csrf-token">` und als
  verstecktes Formularfeld.
- Validierung per `hash_equals` über `X-CSRF-Token`-Header oder `_csrf`-Feld
  auf allen mutierenden Endpunkten.

## SQL-Injection

- Ausschließlich **Prepared Statements** (PDO) mit gebundenen Parametern.
- `PDO::ATTR_EMULATE_PREPARES = false` (native Parameterbindung). Dynamisch
  erzeugte WHERE-Klauseln verwenden ausschließlich gebundene Platzhalter;
  Tabellen-/Spaltennamen werden nie aus Benutzereingaben übernommen.

## XSS

- Alle Ausgaben in Views laufen über den HTML-Escape-Helfer `e()`.
- JSON-Attribute werden über `json_attr()` korrekt enkodiert.

## Kryptographie

- UniFi-API-Tokens werden mit **AES-256-GCM** (authenticated encryption)
  verschlüsselt: Base64 aus `IV(12) || Tag(16) || Ciphertext`.
- Schlüssel wird per SHA-256 aus `APP_SECRET` abgeleitet.
- Manipulierte oder falsch verschlüsselte Werte werden abgelehnt
  (GCM-Authentifizierung).

## Brute-Force-Schutz

- `login_attempts`-Tabelle, Sperre nach 5 Fehlversuchen je Identifikator
  innerhalb von 15 Minuten (`Auth\RateLimiter`).

## Secrets & Logging

- `Logger::redact()` maskiert Tokens, Passwörter und Authorization-Header.
- `.env` ist über `.gitignore` und `.dockerignore` ausgeschlossen.
- `APP_SECRET` muss gesetzt sein; Default-/leere Werte werden von `Crypto`
  mit einer Exception abgelehnt.

## Sicherheits-Header

Nginx setzt `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`
und `Referrer-Policy: strict-origin-when-cross-origin`.

## Bekannte Einschränkungen

- Die lokale UniFi-API erfordert ein gültiges TLS-Zertifikat oder
  `verify_ssl=false` (bei Self-Signed-Zertifikaten). Der Standard im
  Mock-Modus deaktiviert die Zertifikatsprüfung; für produktive Umgebungen
  sollte ein vertrauenswürdiges Zertifikat verwendet werden.
