#!/bin/sh
set -e

echo "[app-entrypoint] Migrationen werden ausgeführt ..."
php /var/www/html/bin/cli.php migrate

echo "[app-entrypoint] TLS-Zertifikat wird bereitgestellt ..."
php /var/www/html/bin/cli.php tls:sync

echo "[app-entrypoint] Starte $*"
exec "$@"
