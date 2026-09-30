# Datenbank

Schema: `database/migrations/001_initial.sql`. Engine InnoDB, Zeichensatz
`utf8mb4`. Die Migrationen sind idempotent (`CREATE TABLE IF NOT EXISTS`).

## Tabellen

| Tabelle               | Zweck                                                        |
|-----------------------|--------------------------------------------------------------|
| `roles`               | Rollen (`admin`, `operator`, `readonly`)                      |
| `users`               | Lokale Anmelde-Benutzer (Passwort als `password_hash`)        |
| `login_attempts`      | Brute-Force-Schutz (Rate-Limiting)                            |
| `unifi_connections`   | Ein UniFi-Controller je Standort; Token verschlüsselt         |
| `unifi_users`         | Cache: Personen                                               |
| `unifi_credentials`   | Cache: RFID-Karten                                           |
| `unifi_access_groups` | Cache: Zutrittsgruppen (Access Policies)                      |
| `unifi_doors`         | Cache: Türen                                                 |
| `audit_logs`          | Revisionssicheres Audit-Log                                  |
| `sync_logs`           | Verlauf der Synchronisationsläufe                            |
| `app_settings`        | Schlüssel-Wert-Einstellungen                                 |

## Cache-Tabellen

Die Cache-Tabellen speichern den original UniFi-Identifier (`unifi_id` bzw.
`unifi_token`) sowie ein `raw_json`-Snapshot des API-Datensatzes. Der
eindeutige Schlüssel kombiniert immer `connection_id` mit dem
UniFi-Identifier, da derselbe Identifier auf verschiedenen Controllern
auftreten kann.

## Fremdschlüssel

- `users.role` → `roles.slug` (`ON DELETE RESTRICT`)
- Cache-Tabellen → `unifi_connections.id` (`ON DELETE CASCADE`)
- `sync_logs.connection_id` → `unifi_connections.id` (`ON DELETE SET NULL`)

## Verschlüsselung

`unifi_connections.api_token_enc` enthält den mit AES-256-GCM verschlüsselten
API-Token (Base64 aus IV + Tag + Ciphertext). Der Schlüssel wird aus
`APP_SECRET` abgeleitet. Der Klartext verlässt die Anwendung nur für den
tatsächlichen API-Aufruf.
