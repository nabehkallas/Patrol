# Development scripts

Reusable checks for working on Patrol: the checking levels in `CLAUDE.md` use them. Run from the
project root in Git Bash. Nothing here is part of the app.

## Local

| Script | What it does |
|---|---|
| `node scripts/dev/browser-check.mjs /pump-counters /cash-box --widths 1366,1920,2560` | Opens pages logged in (Arabic by default), per width: page overflow, each table's scroll, headers on two lines, page errors; saves screenshots (`--out`, default the temp folder). Needs `php artisan serve --port=8001`. |
| `scripts/dev/local-db.sh backup` / `restore [stamp]` / `list` | Copies the local stations' databases aside, and puts them back (then migrates). Use around migrations and tests that add data. |
| `scripts/dev/local-run.sh <script.php> [KEY=VALUE…]` | Runs a script through tinker locally (the same scripts as production), with input closed so tinker exits. |
| `php scripts/dev/add-keys.php keys.php [--replace]` | Adds/removes translation keys in all 11 `lang/*.json`. `keys.php` returns `['add' => [key => [en, ar, fr, es, de, it, tr, ru, ur, hi, ku]], 'remove' => [...]]`. Refuses to overwrite an existing key (unless `--replace`) or to remove one still used in the code. |

## Production

| Script | What it does |
|---|---|
| `scripts/dev/prod-run.sh <script.php> [KEY=VALUE…]` | Runs a script on production through tinker. Refuses a script not marked `READ-ONLY` in its first 5 lines, unless `--write` (only for a data change the user approved). |
| `scripts/dev/prod-backup.sh <label>` | Backs up every production database on the server (`/data/backups-<label>-<time>/`), integrity-checks the copies, downloads them to `~/patrol-prod-backups/<label>-<time>/` (`PROD_BACKUPS_DIR`), outside every git folder, and checks them again. Before any migration or data change. |
| `scripts/dev/deploy.sh [--smoke]` | Deploys (migrations run on release), checks `/login` answers 200; `--smoke` also runs `prod/smoke.php`. Refuses with uncommitted changes. |

Read-only production scripts (`scripts/dev/prod/`), all for `prod-run.sh` or `local-run.sh`:

- `smoke.php`: on every station, the main calculations run without an error (cash box, entry table, statistics, Excel sheet).
- `fingerprint.php`: hashes of every stored row and report figure per station. Take one before a deploy and one after, then `php scripts/dev/fp-compare.php before.json after.json` names any part that changed. `FP_IGNORE=col1,key2` leaves out what the change adds on purpose (new columns, new summary keys); `FP_YEAR` picks the report year.
- `neg-boxes.php`: each cash box's balance, and any day a box went below zero.

When staff add records between the "before" and "after" fingerprints, the counts show it. Then compare old and new code on the backup copy instead: copy it over a local station's database, take a fingerprint at the old commit (`git checkout <old>`), go back to `main`, migrate, take another, compare, then `local-db.sh restore`.
