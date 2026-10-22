# Sourced by the production scripts (deploy.sh, prod-run.sh, prod-backup.sh): uses the
# app-scoped deploy token kept outside the repo, so they never need the personal `fly auth
# login` session (which can reach every app in every org). Create the token once with
#   fly tokens create deploy -a patrol-station --name "patrol scripts" --expiry 2160h
# and save only the token line to the file below.
TOKEN_FILE="${PATROL_FLY_TOKEN_FILE:-$HOME/.config/patrol/fly-token}"

if [[ -z "${FLY_API_TOKEN:-}" && -f "$TOKEN_FILE" ]]; then
    FLY_API_TOKEN="$(tr -d '\r\n' < "$TOKEN_FILE")"
    export FLY_API_TOKEN
fi

if [[ -z "${FLY_API_TOKEN:-}" ]]; then
    echo "Note: no app-scoped Fly token in $TOKEN_FILE -- using your personal fly login." >&2
fi
