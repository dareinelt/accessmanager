#!/bin/sh
set -e

CERT="/var/www/html/storage/tls/live.crt"
KEY="/var/www/html/storage/tls/live.key"

# Wait until the application has written the served certificate (the app
# container generates a fallback certificate during its own startup).
until [ -s "$CERT" ] && [ -s "$KEY" ]; do
    echo "[web-entrypoint] Warte auf TLS-Zertifikat ($CERT) ..."
    sleep 3
done

# Reload nginx whenever the served certificate/key changes.
(
    last=""
    while true; do
        cur="$(cat "$CERT" "$KEY" 2>/dev/null | md5sum)"
        if [ "$cur" != "$last" ]; then
            last="$cur"
            if [ -s /var/run/nginx.pid ]; then
                nginx -s reload >/dev/null 2>&1 || true
            fi
        fi
        sleep 5
    done
) &
WATCHER=$!
trap 'kill "$WATCHER" 2>/dev/null || true' TERM INT QUIT

echo "[web-entrypoint] Starte $*"
exec "$@"
