#!/bin/sh
# Regenerates /etc/litestream.yml from whatever .sqlite files currently exist (the central
# database plus every station's tenant*.sqlite), and (re)starts `litestream replicate` whenever
# that file list changes — e.g. right after a new station is provisioned. Litestream itself has
# no built-in "watch this directory" mode, so this loop is what keeps new tenant databases from
# silently going unbacked-up between deploys.
set -eu

DATA_DIR="${TENANT_SQLITE_PATH:-/var/www/html/database}"
# /tmp rather than /etc: this runs as www-data (a non-root user), which can't write to /etc.
CONFIG_FILE=/tmp/litestream.yml
LITESTREAM_PID=""

if [ -z "${LITESTREAM_BUCKET:-}" ]; then
    echo "[litestream-watch] LITESTREAM_BUCKET not set — continuous backup is disabled." >&2
    echo "[litestream-watch] Station data only lives on the Fly volume until this is configured." >&2
    # Idle forever instead of exiting, so supervisord doesn't treat this as a crash loop.
    while true; do sleep 3600; done
fi

generate_config() {
    {
        echo "dbs:"
        for db in "$DATA_DIR"/*.sqlite; do
            [ -e "$db" ] || continue
            name=$(basename "$db")
            echo "  - path: $db"
            echo "    replicas:"
            echo "      - type: s3"
            echo "        bucket: ${LITESTREAM_BUCKET:-}"
            echo "        path: ${LITESTREAM_PATH_PREFIX:-patrol-station}/${name}"
            echo "        endpoint: ${LITESTREAM_ENDPOINT:-}"
            echo "        region: ${LITESTREAM_REGION:-auto}"
            echo "        access-key-id: ${LITESTREAM_ACCESS_KEY_ID:-}"
            echo "        secret-access-key: ${LITESTREAM_SECRET_ACCESS_KEY:-}"
        done
    } > "$CONFIG_FILE"
}

while true; do
    before=""
    [ -f "$CONFIG_FILE" ] && before=$(md5sum "$CONFIG_FILE" | cut -d' ' -f1)

    generate_config

    after=$(md5sum "$CONFIG_FILE" | cut -d' ' -f1)

    running=false
    if [ -n "$LITESTREAM_PID" ] && kill -0 "$LITESTREAM_PID" 2>/dev/null; then
        running=true
    fi

    if [ "$before" != "$after" ] || [ "$running" = false ]; then
        if [ "$running" = true ]; then
            echo "[litestream-watch] db list changed, restarting litestream"
            kill "$LITESTREAM_PID" 2>/dev/null || true
            wait "$LITESTREAM_PID" 2>/dev/null || true
        fi

        litestream replicate -config "$CONFIG_FILE" &
        LITESTREAM_PID=$!
    fi

    sleep 60
done
