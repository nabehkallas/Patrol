#!/usr/bin/env bash
# Runs a PHP script on production through `php artisan tinker` (every station: the script loops
# over Tenant::all() itself) and prints its output.
#
#   scripts/dev/prod-run.sh scripts/dev/prod/neg-boxes.php
#   scripts/dev/prod-run.sh scripts/dev/prod/fingerprint.php FP_IGNORE=original_amount,opening_cash > before.json
#   scripts/dev/prod-run.sh --write my-fix.php          # only after the user approved a data change
#
# Safety: a script must say READ-ONLY in its first 5 lines, or the run is refused unless --write
# is given. KEY=VALUE arguments become environment variables for the script (getenv()).
set -euo pipefail

APP="${FLY_APP:-patrol-station}"
WRITE=0
if [[ "${1:-}" == "--write" ]]; then
    WRITE=1
    shift
fi

SCRIPT="${1:?usage: prod-run.sh [--write] script.php [KEY=VALUE ...]}"
shift
[[ -f "$SCRIPT" ]] || { echo "No such script: $SCRIPT" >&2; exit 1; }

if [[ $WRITE -eq 0 ]] && ! head -n 5 "$SCRIPT" | grep -q 'READ-ONLY'; then
    echo "Refused: $SCRIPT is not marked READ-ONLY in its first 5 lines (pass --write only for an approved data change)." >&2
    exit 1
fi

ENV=""
for pair in "$@"; do
    [[ "$pair" =~ ^[A-Z_][A-Z0-9_]*=[A-Za-z0-9_.,:/-]*$ ]] || { echo "Bad KEY=VALUE: $pair" >&2; exit 1; }
    ENV+="$pair "
done

B64=$(base64 -w0 "$SCRIPT")
# Git Bash would rewrite the /tmp paths; flyctl prints connection noise and a Windows console
# warning ("The handle is invalid") that aren't the script's output.
MSYS_NO_PATHCONV=1 timeout 300 flyctl ssh console -a "$APP" \
    -C "sh -c 'echo $B64 | base64 -d > /tmp/dev-run.php && cd /var/www/html && env $ENV php artisan tinker /tmp/dev-run.php; rm -f /tmp/dev-run.php'" 2>&1 \
    | grep -v -e '^Connecting' -e 'handle is invalid' -e '^> *$' || true
