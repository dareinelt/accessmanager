# UniFi API

## Grundsatz

Die Anwendung nutzt ausschließlich die **lokale** UniFi Access API. Jeder
Controller stellt sie unter `https://<host>:12445` bereit und akzeptiert einen
Bearer-Token:

```
Authorization: Bearer <api-token>
```

Eine **Cloud-API** wird nicht verwendet, da sie kein CRUD für Benutzer,
Karten oder Zutrittsgruppen bietet.

## Verwendete Endpunkte

Die Implementierung (`app/Api/UniFiApiClient.php`) basiert auf der
öffentlichen OpenAPI-Spezifikation der UniFi Access API (v4.x). Genutzt werden:

| Bereich          | Endpunkte                                                                 |
|------------------|---------------------------------------------------------------------------|
| Benutzer         | `GET/POST /api/v1/developer/users`, `GET/PUT/DELETE /api/v1/developer/users/{id}` |
| Zutrittsgruppen  | `GET/POST /api/v1/developer/users/{id}/access_policies`, `PUT /api/v1/developer/users/{id}/access_policies` |
| Karten           | `GET/POST /api/v1/developer/users/{id}/nfc_cards`, `POST /api/v1/developer/credentials/nfc_cards/delete`, `GET /api/v1/developer/credentials/nfc_cards/tokens` |
| Access Policies  | `GET/POST /api/v1/developer/access_policies`, `GET/PUT/DELETE /api/v1/developer/access_policies/{id}` |
| Türen            | `GET /api/v1/developer/doors`, `POST /api/v1/developer/doors/{id}/unlock` |

## Antwort-Envelope

UniFi antwortet mit:

```json
{
  "code": "SUCCESS",
  "msg": "…",
  "data": { … },
  "pagination": { "page_num": 1, "page_size": 25, "total": 100 }
}
```

Der Client übersetzt jeden von `SUCCESS` abweichenden `code` in eine
`UniFiApiException`. Paginierte Listen werden über `pagination.total`
vollständig abgerufen.

## Datenmodelle (Auszug)

**User**

```json
{
  "id": "…", "first_name": "…", "last_name": "…", "full_name": "…",
  "user_email": "…", "employee_number": "…",
  "status": "ACTIVE | PENDING | DEACTIVATED",
  "onboard_time": 1787738262, "nfc_cards": [], "access_policy_ids": [], "pin_code": null
}
```

**NFC-Karte**

```json
{ "token": "…", "display_id": "…", "status": "…", "alias": "…",
  "card_type": "…", "user_id": "…", "user_type": "…" }
```

**Access Policy (Zutrittsgruppe)**

```json
{ "id": "…", "name": "…",
  "resources": [ { "type": "door | door_group", "id": "…" } ],
  "schedule_id": "…" }
```

## Standorte

Ein „Standort“ in der Anwendung entspricht genau **einem** UniFi-Controller
(Zeile in `unifi_connections`). Es gibt keinen separaten „Sites“-Endpunkt in
der UniFi-API; alle Daten sind bereits controller-lokal.

## Mock-Client

`UNIFI_API_MOCK=true` aktiviert `MockUniFiApiClient` (`app/Api/`). Er:

- erzeugt deterministisch ~48 Personen, ~10 Gruppen, 16 Türen und 70 Karten,
- persistiert seinen Zustand in `storage/cache/mock-{id}.json`,
- implementiert dieselbe Schnittstelle wie der echte Client, sodass CRUD,
  Zuweisung und Tür-Öffnung lokal testbar sind.
