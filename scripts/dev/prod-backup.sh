#!/usr/bin/env bash
# Backs up every production database before a migration or a data change: a consistent copy
# on the server (/data/backups-<label>-<time>/), integrity-checked, then downloaded to
# ~/patrol-prod-backups/<label>-<time>/ (PROD_BACKUPS_DIR) and checked again locally. Writes nothing but the copies.
#
#   scripts/dev/prod-backup.sh pre-shifts-part-2
set -euo pipefail
cd "$(dirname "$0")/../.."

LABEL="${1:?usage: prod-backup.sh <label>  (e.g. pre-shifts-part-2)}"
[[ "$LABEL" =~ ^[a-z0-9-]+$ ]] || { echo "Label: lowercase letters, digits and dashes only." >&2; exit 1; }
APP="${FLY_APP:-patrol-station}"
source scripts/dev/fly-token.sh
# Production copies stay outside every git folder (they hold all stations' data).
BACKUPS="${PROD_BACKUPS_DIR:-$(cygpath -m "$HOME" 2>/dev/null || echo "$HOME")/patrol-prod-backups}"

B64=$(base64 -w0 scripts/dev/prod/backup.php)
OUT=$(MSYS_NO_PATHCONV=1 timeout 300 flyctl ssh console -a "$APP" \
    -C "sh -c 'echo $B64 | base64 -d > /tmp/dev-backup.php && BACKUP_LABEL=$LABEL php /tmp/dev-backup.php; rm -f /tmp/dev-backup.php'" 2>&1 \
    | grep -v -e '^Connecting' -e 'handle is invalid' || true)
# (flyctl exits non-zero on Windows after "The handle is invalid", so its status isn't used:
# the backup folder and the integrity checks below decide.)
echo "$OUT"

DIR=$(echo "$OUT" | sed -n 's/.*"dir": *"\([^"]*\)".*/\1/p' | sed 's#\\/#/#g')
[[ -n "$DIR" ]] || { echo "No backup folder in the output: stopping." >&2; exit 1; }
if echo "$OUT" | grep -q '"integrity": *"[^o]'; then
    echo "A server copy failed its integrity check: stopping." >&2
    exit 1
fi

LOCAL="$BACKUPS/$(basename "$DIR" | sed 's/^backups-//')"
mkdir -p "$LOCAL"
for file in $(echo "$OUT" | sed -n 's/^ *"\([^"]*\.sqlite\)": {.*/\1/p'); do
    MSYS_NO_PATHCONV=1 timeout 300 flyctl ssh sftp get -a "$APP" "$DIR/$file" "$LOCAL/$file" >/dev/null
    php -r '$p = new PDO("sqlite:".$argv[1]); echo basename($argv[1]), ": ", $p->query("PRAGMA integrity_check")->fetchColumn(), "\n";' "$LOCAL/$file"
done
echo "Server: $DIR"
echo "Local:  $LOCAL"
