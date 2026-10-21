#!/usr/bin/env bash
# The local stations' databases (database/tenant*.sqlite): back them up before a migration or a
# test that adds data, and put them back afterwards -- quicker and surer than deleting test data.
#
#   scripts/dev/local-db.sh backup          # copies each to <file>.bak-<time>, prints the time
#   scripts/dev/local-db.sh restore         # puts back the newest backup, then migrates again
#   scripts/dev/local-db.sh restore 20261021020642
#   scripts/dev/local-db.sh list
set -euo pipefail
cd "$(dirname "$0")/../.."

case "${1:-}" in
    backup)
        stamp=$(date +%Y%m%d%H%M%S)
        for f in database/tenant*.sqlite; do cp "$f" "$f.bak-$stamp"; done
        echo "Backed up as .bak-$stamp"
        ;;
    restore)
        stamp="${2:-$(ls database/tenant*.sqlite.bak-* | sed 's/.*\.bak-//' | sort | tail -1)}"
        [[ -n "$stamp" ]] || { echo "No backup found." >&2; exit 1; }
        for f in database/tenant*.sqlite.bak-"$stamp"; do
            target="${f%.bak-$stamp}"
            cp "$f" "$target"
            rm -f "$target-wal" "$target-shm"
        done
        echo "Restored .bak-$stamp; migrating:"
        timeout 180 php artisan tenants:migrate | grep -E 'DONE|Nothing|ERROR' || true
        ;;
    list)
        ls database/tenant*.sqlite.bak-* 2>/dev/null | sed 's/.*\.bak-//' | sort | uniq -c
        ;;
    *)
        echo "usage: local-db.sh backup | restore [stamp] | list" >&2
        exit 2
        ;;
esac
