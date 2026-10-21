#!/usr/bin/env bash
# Runs a PHP script locally through `php artisan tinker` (the same scripts prod-run.sh runs), with
# a time limit. Input comes from /dev/null: otherwise tinker, once the script is done, waits
# for keyboard input until the time limit (the "tinker hangs" seen before).
#
#   scripts/dev/local-run.sh scripts/dev/prod/fingerprint.php FP_IGNORE=opening_cash
set -euo pipefail
cd "$(dirname "$0")/../.."

SCRIPT="${1:?usage: local-run.sh script.php [KEY=VALUE ...]}"
shift
timeout 400 env "$@" php artisan tinker "$SCRIPT" < /dev/null
