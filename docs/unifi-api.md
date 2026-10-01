# UniFi API

> Screenshots der Oberfläche und eine bebilderte Anleitung finden Sie in der
> [Bedienungsanleitung](user-guide.md).

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
öffentlichen OpenAPI-Spezifikation der UniFi Access API. Die HTTP-Methoden
wurden gegen die Spezifikation geprüft
([unifi-access-api-openapi](https://github.com/YuDefine/unifi-access-api-openapi)):
Zuweisungen und Aktionen verwenden **PUT**, nur das Anlegen **POST**.

| Bereich          | Methode | Endpunkt                                                     |
|------------------|---------|--------------------------------------------------------------|
| Benutzer         | GET / POST | `/api/v1/developer/users`                                 |
|                  | GET / PUT / DELETE | `/api/v1/developer/users/{id}`                    |
| Zutrittsgruppen einer Person | PUT | `/api/v1/developer/users/{id}/access_policies`     |
| Karte zuweisen   | PUT     | `/api/v1/developer/users/{id}/nfc_cards`                     |
| Karte trennen    | PUT     | `/api/v1/developer/users/{id}/nfc_cards/delete`              |
| Karten           | GET     | `/api/v1/developer/credentials/nfc_cards/tokens[/{token}]`   |
| Access Policies  | GET / POST | `/api/v1/developer/access_policies`                       |
|                  | GET / PUT / DELETE | `/api/v1/developer/access_policies/{id}`          |
| Türen            | GET     | `/api/v1/developer/doors[/{id}]`                             |
| Tür öffnen       | PUT     | `/api/v1/developer/doors/{id}/unlock`                        |

## Wiederholungen und Duplikatschutz

- Netzwerkfehler werden für **GET/PUT/DELETE** bis zu zweimal wiederholt.
- **POST** (Person/Zutrittsgruppe anlegen) wird nur wiederholt, wenn die
  Anfrage den Server nachweislich nie erreicht hat (Host nicht auflösbar,
  Verbindung abgelehnt, keine Bytes gesendet). Bricht die Verbindung danach
  ab, meldet der Client „Ergebnis unklar“ (`UniFiApiException::outcomeUnknown()`)
  statt erneut zu senden – sonst könnten doppelte Personen/Gruppen entstehen.
- Der AD-Sync prüft vor dem Anlegen einer Person zusätzlich live am
  Controller (E-Mail, Personalnummer), ob sie bereits existiert.
- UniFi-Sync und AD-Sync laufen pro Standort nie parallel (DB-Sperre
  `GET_LOCK('uam_connection_<id>')`).

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
