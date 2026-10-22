#!/usr/bin/env bash
# Deploys to Fly.io (migrations run on release), then checks the app answers and -- with
# --smoke -- runs the read-only smoke check on every station. Push the commit first.
#
#   scripts/dev/deploy.sh            # UI-only changes
#   scripts/dev/deploy.sh --smoke    # logic, data or money changes
set -euo pipefail
cd "$(dirname "$0")/../.."
APP="${FLY_APP:-patrol-station}"
source scripts/dev/fly-token.sh

if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
    echo "Uncommitted changes: commit (and push) before deploying." >&2
    exit 1
fi

timeout 590 flyctl deploy -a "$APP" 2>&1 | tail -3

status=$(curl -s -o /dev/null -w '%{http_code}' "https://$APP.fly.dev/login")
echo "login: $status"
[[ "$status" == "200" ]] || { echo "The app doesn't answer 200 on /login." >&2; exit 1; }

if [[ "${1:-}" == "--smoke" ]]; then
    scripts/dev/prod-run.sh scripts/dev/prod/smoke.php
fi
