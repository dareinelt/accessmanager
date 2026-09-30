# Datenbank

> Screenshots der Oberfläche und eine bebilderte Anleitung finden Sie in der
> [Bedienungsanleitung](user-guide.md).

Schema: `database/migrations/001_initial.sql`. Engine InnoDB, Zeichensatz
`utf8mb4`. Die Migrationen sind idempotent (`CREATE TABLE IF NOT EXISTS`).

## Tabellen

| Tabelle               | Zweck                                                        |
|-----------------------|--------------------------------------------------------------|
| `roles`               | Rollen (`sysadmin`, `admin`, `operator`, `readonly`)          |
| `users`               | Lokale Anmelde-Benutzer (Passwort als `password_hash`)        |
| `system_secrets`      | Verschlüsselte Systemdaten (AD, DNs, API-Endpunkte)           |
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

Analog speichert `system_secrets.value_enc` vertrauliche Systemdaten
(AD-Daten, DNs, API-Endpunkte) AES-256-GCM verschlüsselt. Zugriff nur für
`sysadmin`; Anzeige und Änderung werden im Audit-Log protokolliert.

## Backup & Wiederherstellung

Ein Backup umfasst die konfigurierbaren Tabellen (`roles`, `app_settings`,
`users`, `unifi_connections`, `system_secrets`, `tls_certificates`,
`ad_group_mappings`) sowie die Cache-Tabellen (`unifi_users`,
`unifi_credentials`, `unifi_access_groups`, `unifi_doors`) als „doppelten
Boden". Betriebslogs (`audit_logs`, `sync_logs`, `login_attempts`) sind nicht
Bestandteil des Backups.

Die Planung wird über `app_settings` gesteuert: `backup_enabled`
(`1`/leer), `backup_interval_minutes`, `backup_retention` und
`backup_last_run` (vom Cron gesetzt).
