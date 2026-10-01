# Sicherheit

> Screenshots der Oberfläche und eine bebilderte Anleitung finden Sie in der
> [Bedienungsanleitung](user-guide.md).

## Authentifizierung & Session

- Lokale Anmeldung gegen `users`; neue Passwörter werden mit **Argon2id**
  gehasht (`Auth::hashPassword()`, Fallback `PASSWORD_DEFAULT`), alte Hashes
  werden beim Login per `password_needs_rehash` migriert. Unbekannte
  Benutzernamen durchlaufen ebenfalls `password_verify` (Timing-Schutz).
- Session-Cookie: `HttpOnly`, `SameSite=Lax`, `Secure` bei HTTPS, Name
  `SESSION_NAME`; `session.use_strict_mode` verhindert Session-Fixation.
- `SESSION_LIFETIME` ist ein **Inaktivitäts-Timeout** in Sekunden
  (mindestens 300); danach wird die Session serverseitig beendet.
- Session-Regenerierung (`session_regenerate_id(true)`) bei erfolgreichem Login,
  vollständige Neuanlage der Session beim Logout.
- Der angemeldete Benutzer wird bei **jedem Request** aus der Datenbank
  nachgeladen: deaktivierte/gelöschte Konten werden sofort abgemeldet,
  Rollenänderungen greifen unmittelbar.
- Logout erfolgt ausschließlich per POST mit CSRF-Token.
- Client-IP: `X-Forwarded-For`/`X-Forwarded-Proto` werden nur von Proxys aus
  `TRUSTED_PROXIES` akzeptiert (Schutz vor IP-Spoofing in Audit-Log und
  Rate-Limiter).

## Autorisierung / Rollen

| Rolle       | Rechte                                                              |
|-------------|---------------------------------------------------------------------|
| `sysadmin`  | Höchste Stufe: zusätzlich Systemgeheimnisse und Backup/Restore       |
| `admin`     | Standorte, Audit, Benutzer, Einstellungen, Zertifikate               |
| `operator`  | Personen/Karten/Gruppen/Türen verwalten, Synchronisation ausführen   |
| `readonly`  | Nur Lesen (PIN-Codes und sensible Personendaten werden maskiert)     |

Rollen sind hierarchisch (`App\Security\Role`, Rang-Vergleich). Regeln der
Benutzerverwaltung (`AppUserService`):

- Ein Administrator kann nur Rollen bis einschließlich `admin` vergeben und
  keine `sysadmin`-Konten bearbeiten, deaktivieren oder löschen.
- Niemand kann die eigene Rolle ändern, sich selbst deaktivieren oder löschen.
- Das Standard-Administratorkonto ist vor Löschung/Herabstufung geschützt.

## Systemgeheimnisse

Vertrauliche Systemdaten (Active-Directory-Daten, DNs, API-Endpunkte der
UniFi-Geräte u. ä.) werden in der Tabelle `system_secrets` abgelegt. Der
Wert liegt dort ausschließlich mit AES-256-GCM verschlüsselt vor
(`value_enc`). Lesender und schreibender Zugriff ist auf die Rolle
`sysadmin` beschränkt (`SystemSecretController` prüft `Auth::ROLE_SYSADMIN`);
das Anzeigen eines Wertes (`reveal`) sowie jede Änderung wird im Audit-Log
protokolliert.

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
- Clientseitig erzeugtes HTML maskiert Werte über `UAM.esc()`; Benutzerdaten
  werden nicht mehr in Inline-Event-Handler (`onclick`) eingebettet, sondern
  über `data-*`-Attribute übergeben.
- `LIKE`-Suchen maskieren `%`, `_` und `\` (`CatalogRepository::escapeLike`).

## CSV-Export

- Zellen, die mit `=`, `+`, `-`, `@`, Tab oder CR beginnen, werden mit `'`
  präfixiert (Schutz vor Formel-Injection in Excel/LibreOffice).
- Antworten werden mit `Cache-Control: no-store` ausgeliefert.

## Datei-Uploads

- Nur echte Uploads (`is_uploaded_file`) werden akzeptiert, mit
  Größenlimit (`BaseController::readUpload()`).

## Kryptographie

- UniFi-API-Tokens werden mit **AES-256-GCM** (authenticated encryption)
  verschlüsselt: Base64 aus `IV(12) || Tag(16) || Ciphertext`.
- Schlüssel wird per SHA-256 aus `APP_SECRET` abgeleitet.
- Manipulierte oder falsch verschlüsselte Werte werden abgelehnt
  (GCM-Authentifizierung).

## Brute-Force-Schutz

- `login_attempts`-Tabelle, Sperre nach 5 Fehlversuchen je Identifikator
  sowie nach 20 Fehlversuchen je IP-Adresse (Password-Spraying) innerhalb von
  15 Minuten (`Auth\RateLimiter`).

## Backup & Wiederherstellung

- Backups sind **unverschlüsselt** (JSON) und nur für vertrauenswürdige
  Speicherorte bestimmt. Verschlüsselte Datenbankwerte (API-Tokens,
  Systemgeheimnisse, private TLS-Schlüssel) werden unverändert als Chiffretext
  exportiert und bleiben damit an das `APP_SECRET` der erzeugenden
  Installation gebunden.
- Zugriff auf Backup, Download und Wiederherstellung erfordert die Rolle
  `sysadmin` (ein Backup enthält alle Passwort-Hashes; eine Wiederherstellung
  könnte sonst zur Rechteausweitung genutzt werden); alle mutierenden Aktionen
  sind CSRF-geschützt und werden im Audit-Log protokolliert.
- Die Wiederherstellung ersetzt die abgedeckten Tabellen vollständig und
  erhält das Benutzerkonto des ausführenden Administrators, damit sich
  niemand aussperren kann.

## Secrets & Logging

- `Logger::redact()` maskiert Tokens, Passwörter und Authorization-Header.
- `.env` ist über `.gitignore` und `.dockerignore` ausgeschlossen.
- `APP_SECRET` muss gesetzt sein; Default-/leere Werte werden von `Crypto`
  mit einer Exception abgelehnt.

## Sicherheits-Header

Nginx setzt `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
`Referrer-Policy: strict-origin-when-cross-origin`, eine
`Content-Security-Policy` (`default-src 'self'`, `frame-ancestors 'none'`,
`form-action 'self'`), `Permissions-Policy` und
`Cross-Origin-Opener-Policy`; `server_tokens` ist deaktiviert.
HSTS ist vorbereitet, aber auskommentiert, solange das selbstsignierte
Notfall-Zertifikat verwendet wird.

## Bekannte Einschränkungen

- Die lokale UniFi-API erfordert ein gültiges TLS-Zertifikat oder
  `verify_ssl=false` (bei Self-Signed-Zertifikaten). Der Standard im
  Mock-Modus deaktiviert die Zertifikatsprüfung; für produktive Umgebungen
  sollte ein vertrauenswürdiges Zertifikat verwendet werden.
