# Interne API

> Screenshots der Oberfläche und eine bebilderte Anleitung finden Sie in der
> [Bedienungsanleitung](user-guide.md).

Alle JSON-Endpunkte liegen unter `/api/*` und erfordern eine aktive Session
(Authentifizierung). Antwortformat:

```json
{ "success": true, "data": { … } }
```

bzw. bei Fehlern:

```json
{ "success": false, "error": { "code": "…", "message": "…" } }
```

## Authentifizierung & CSRF

- **GET**-Endpunkte benötigen nur eine angemeldete Session (Cookie).
- **Mutierende** Endpunkte (`POST`, `PUT`, `DELETE`) benötigen zusätzlich ein
  gültiges CSRF-Token – per Header `X-CSRF-Token` oder Feld `_csrf`.
- Rollenprüfung: `admin`/`operator` für Verwaltungsoperationen, `admin` für
  Standorte, Audit, Benutzer und Einstellungen.

## Fehlercodes

| HTTP | Code           | Bedeutung                          |
|------|----------------|------------------------------------|
| 400  | `UNIFI_ERROR`  | Fehler der UniFi-API               |
| 401  | `UNAUTHENTICATED` | Nicht angemeldet                |
| 403  | `FORBIDDEN`    | Fehlende Rolle oder CSRF           |
| 404  | `NOT_FOUND`    | Route nicht gefunden               |
| 422  | `VALIDATION`   | Ungültige Eingabe                  |
| 500  | `SERVER_ERROR` | Unerwarteter Fehler                |

## Dashboard

| Methode | Pfad           | Beschreibung      |
|---------|----------------|-------------------|
| GET     | `/api/dashboard` | Statistiken      |

## Personen

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| GET  | `/api/persons` | Liste (Filter: `connection_id`, `search`, `status`, `group_id`, `card_filter`, `sort`, `page`, `page_size`) |
| GET  | `/api/persons/{connection_id}/{unifi_id}` | Detail |
| POST | `/api/persons` | Anlegen (JSON: `connection_id`, `first_name`, `last_name`, `user_email`, `employee_number`, `status`, `pin_code`, …) |
| PUT  | `/api/persons/{connection_id}/{unifi_id}` | Aktualisieren |
| DELETE | `/api/persons/{connection_id}/{unifi_id}` | Löschen |
| PUT  | `/api/persons/{connection_id}/{unifi_id}/card` | Karte zuweisen (`token`) |
| DELETE | `/api/persons/{connection_id}/{unifi_id}/card` | Karte trennen (`token`) |
| PUT  | `/api/persons/{connection_id}/{unifi_id}/groups` | Gruppen setzen (`access_policy_ids[]`) |

## Karten (Credentials)

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| GET | `/api/credentials` | Liste (Filter: `connection_id`, `search`, `status`, `card_filter`) |
| GET | `/api/credentials/{connection_id}/{token}` | Detail |

## Zutrittsgruppen

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| GET  | `/api/groups` | Liste (`connection_id` optional) |
| GET  | `/api/groups/{connection_id}/{unifi_id}` | Detail |
| POST | `/api/groups` | Anlegen (`connection_id`, `name`, `schedule_id`, `resources[]`) |
| PUT  | `/api/groups/{connection_id}/{unifi_id}` | Aktualisieren |
| DELETE | `/api/groups/{connection_id}/{unifi_id}` | Löschen |

## Türen

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| GET  | `/api/doors` | Liste (`connection_id` optional) |
| POST | `/api/doors/{connection_id}/{unifi_id}/unlock` | Tür öffnen |

## Standorte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| GET  | `/api/sites` | Liste |
| GET  | `/api/sites/{id}` | Detail |
| POST | `/api/sites` | Anlegen (`name`, `host`, `port`, `api_token`, `verify_ssl`) |
| PUT  | `/api/sites/{id}` | Aktualisieren |
| DELETE | `/api/sites/{id}` | Löschen |
| POST | `/api/sites/{id}/test` | Verbindungstest |

## Synchronisation

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| GET  | `/api/sync` | Status (letzter Erfolg/Fehler, Verlauf) |
| POST | `/api/sync` | Synchronisation ausführen |

## Audit

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| GET | `/api/audit` | Liste (`search`, `page`, `page_size`) |

## Benutzer

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| GET  | `/api/users` | Liste |
| POST | `/api/users` | Anlegen (`username`, `email`, `password`, `role`) |
| PUT  | `/api/users/{id}` | Aktualisieren |
| DELETE | `/api/users/{id}` | Löschen |

## Einstellungen

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| GET | `/api/settings` | Einstellungen lesen |
| PUT | `/api/settings` | Einstellungen schreiben |

Schreibbare Schlüssel: `sync_enabled`, `sync_interval_minutes`,
`backup_enabled`, `backup_interval_minutes`, `backup_retention`.

## CSV-Export (Web, keine JSON-API)

| Methode | Pfad | Inhalt |
|---------|------|--------|
| GET | `/export/persons` | Personen als `personen.csv` |
| GET | `/export/credentials` | Karten als CSV |
| GET | `/export/audit` | Audit-Log als CSV |

## Backup & Wiederherstellung (Web, keine JSON-API)

Alle Routen erfordern die Rolle `admin` (bzw. `sysadmin`).

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| GET  | `/backup` | Seite „Backup & Wiederherstellung" |
| GET  | `/backup/download` | Sofort-Backup als JSON-Download |
| GET  | `/backup/{filename}/download` | Gespeichertes Archiv herunterladen |
| POST | `/backup/create` | Backup im Ordner `storage/backups` ablegen |
| POST | `/backup/delete` | Gespeichertes Archiv löschen (`filename`) |
| POST | `/backup/restore/preview` | JSON-Datei hochladen und Vorschau erzeugen (`backup_file`) |
| POST | `/backup/restore/confirm` | Wiederherstellung bestätigen |
| POST | `/backup/restore/discard` | Wiederherstellung abbrechen |
