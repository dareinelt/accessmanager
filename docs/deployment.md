# Deployment

## Voraussetzungen

- Docker Engine mit Docker Compose (v2)
- Erreichbarkeit der UniFi-Controller aus dem Container-Netzwerk heraus
  (Host `unifi-…` bzw. IP, Port `12445`)

## Konfiguration

1. `.env.example` nach `.env` kopieren.
2. `APP_SECRET` auf einen sicheren Zufallswert setzen:
   ```bash
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```
3. `UNIFI_API_MOCK=false` setzen und je Standort später in der Oberfläche
   einen echten Controller mit API-Token anlegen.
4. Datenbank-Zugangsdaten (`DB_*`) und initialen Admin (`ADMIN_*`) anpassen.

## Start

```bash
docker compose up -d --build
docker compose run --rm app php bin/cli.php migrate
docker compose run --rm app php bin/cli.php seed
```

Die Anwendung ist anschließend unter `http://localhost:8080` erreichbar
(Nginx, Port `8080` → `80`).

## Dienste

| Dienst | Container  | Rolle                                             |
|--------|------------|---------------------------------------------------|
| `web`  | `uam_web`  | Nginx, statische Assets, Reverse-Proxy zu PHP-FPM |
| `app`  | `uam_app`  | PHP-FPM (Applikationscode)                        |
| `db`   | `uam_db`   | MariaDB 11.4                                      |
| `cron` | `uam_cron` | Periodische Synchronisation + Backups (Schleife, 300 s) |

## Daten & Persistenz

- `db_data` – MariaDB-Daten.
- `app_storage` – Logs, Cache und Backups (`storage/logs`, `storage/cache`, `storage/backups`).

Beide Volumes werden zwischen Neustarts beibehalten.

## CLI

```bash
docker compose run --rm app php bin/cli.php migrate
docker compose run --rm app php bin/cli.php seed
docker compose run --rm app php bin/cli.php sync
docker compose run --rm app php bin/cli.php backup:run
docker compose run --rm app php bin/cli.php create-admin <user> <mail> <pass> [role]
```

Der Cron-Dienst führt `backup:run` bei jedem Takt aus; der Befehl prüft
selbst, ob ein Backup fällig ist (`backup_enabled`, `backup_interval_minutes`)
und hält nur die konfigurierte Anzahl Archive vor (`backup_retention`).

## Produktionshinweise

- `APP_DEBUG=false` setzen.
- `APP_URL`, TLS-Terminierung und ggf. `secure`-Cookies über einen
  vorgelagerten Reverse-Proxy realisieren.
- Die `web`-Nginx-Konfiguration setzt bereits `X-Content-Type-Options`,
  `X-Frame-Options` und `Referrer-Policy`.
- `cron`-Dienst ersetzt die Synchronisation in einem 300-Sekunden-Takt;
  für produktive Umgebungen den Takt bzw. einen externen Scheduler erwägen.
