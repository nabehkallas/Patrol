#!/bin/sh
# Runs once per container boot, before supervisord starts. Its whole job is to make sure real
# station data is on disk *before* Laravel/stancl-tenancy gets a chance to touch it — SQLite
# silently creates an empty file the first time something opens a missing path, which would
# turn a lost/fresh volume into permanent, silent data loss for that station.
set -eu

DATA_DIR="${TENANT_SQLITE_PATH:-/var/www/html/database}"
CENTRAL_DB="${DB_DATABASE:-$DATA_DIR/database.sqlite}"

mkdir -p "$DATA_DIR"

restore_if_missing() {
    file="$1"
    name=$(basename "$file")

    if [ -f "$file" ]; then
        return 0
    fi

    if [ -z "${LITESTREAM_BUCKET:-}" ]; then
        return 0
    fi

    echo "[entrypoint] $name missing locally, checking backup..."

    cfg=$(mktemp)
    cat > "$cfg" <<CFG
dbs:
  - path: $file
    replicas:
      - type: s3
        bucket: ${LITESTREAM_BUCKET}
        path: ${LITESTREAM_PATH_PREFIX:-patrol-station}/$name
        endpoint: ${LITESTREAM_ENDPOINT:-}
        region: ${LITESTREAM_REGION:-auto}
        access-key-id: ${LITESTREAM_ACCESS_KEY_ID:-}
        secret-access-key: ${LITESTREAM_SECRET_ACCESS_KEY:-}
CFG

    if litestream restore -if-replica-exists -config "$cfg" "$file"; then
        echo "[entrypoint] restored $name from backup"
    else
        echo "[entrypoint] no backup found for $name — starting fresh"
    fi

    rm -f "$cfg"
}

# 1. The central database (tenant registry, platform admins, sessions) has to exist before we
#    can even know which stations to look for.
restore_if_missing "$CENTRAL_DB"

php artisan migrate --force

# 2. Every station registered in the central DB, restored individually.
if [ -f "$CENTRAL_DB" ] && command -v sqlite3 >/dev/null 2>&1; then
    sqlite3 "$CENTRAL_DB" "SELECT id FROM tenants;" 2>/dev/null | while IFS= read -r tenant_id; do
        [ -n "$tenant_id" ] || continue
        restore_if_missing "$DATA_DIR/tenant${tenant_id}.sqlite"
    done
fi

php artisan tenants:migrate --force || true

# Everything above (migrations, litestream restores) runs as root, so the database files on
# the volume end up root-owned — but php-fpm's workers run as www-data and can't write to
# them, which fails every request that touches the database (e.g. saving the session) with
# "attempt to write a readonly database". Fix ownership before handing off to supervisord.
chown -R www-data:www-data "$DATA_DIR"

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
