# Patrol Station

A database-per-tenant multi-tenant SaaS for managing fuel stations (fuel inventory, pumps, cash box, debts, Sadcop supplier ledger, shop items). Sold to station owners, onboarded manually — no self-serve signup.

**Stack:** Laravel 13, Inertia v3 (`@inertiajs/react`), React 19, TypeScript, Tailwind v4, Vite, `stancl/tenancy` v3.10, Spatie `laravel-permission`, Wayfinder (typed route helpers).

**Production:** Fly.io app `patrol-station` — https://patrol-station.fly.dev. Deploy with `fly deploy -a patrol-station`.

## Multi-tenancy

- **Database-per-tenant**: one SQLite file per station. Tenant migrations live in `database/migrations/tenant/`; central migrations (tenants, tenant_user_directory, central users for the platform super admin) stay in the normal `database/migrations/` path.
- `app/Tenancy/SqliteDatabaseManager.php` resolves tenant DB file paths via `config('tenancy.database.sqlite_path')` (points at the persistent Fly volume `/data` in production, `database/` locally).
- Tenant identification is **login-based**, not domain-based: one URL for everyone. `session('tenant_id')` + `InitializeTenancyFromSession` middleware re-initializes tenant context every request. Fortify's `authenticateUsing` checks the central `tenant_user_directory` first, then switches into that tenant's DB to verify the password.
- Middlewares: `RequireTenant` (redirects super admins away from tenant routes), `RequireSuperAdmin` (mirror, for `/platform/*`), `RequireOnboarding` (gates operational routes behind the first-run wizard until `tenant('onboarded_at')` is set), `ForcePasswordChange` (temp-password flow for freshly provisioned stations).
- `FilesystemTenancyBootstrapper` is **deliberately not registered** in `config/tenancy.php` — this app never stores per-tenant files, and that bootstrapper rewrites Vite asset URLs, which broke the production JS/CSS bundle. Don't re-add it.
- Admin (`admin/*`) routes are intentionally *not* gated by `RequireOnboarding` — the wizard only collects opening balances; tanks/pumps/fuel types are set up through the normal admin CRUD first.

## Arabic / i18n — read this before adding any user-facing string

- **Backend**: `lang/ar.json` is a Laravel JSON translation file. Every `__('Some string')` call in PHP needs a matching entry here or it silently falls back to raw English in Arabic mode — there is no other Arabic translation source for backend strings. PDF exports use a separate, parallel pattern: inline `app()->getLocale() === 'ar' ? [...] : [...]` arrays per controller (do not try to route those through `__()`).
- **Frontend**: `resources/js/lib/i18n.ts` is a flat dictionary (`en`/`ar` keyed the same way), read via `useTranslation()`'s `t()`. **`t()` does not support interpolation/placeholders** — build dynamic strings by concatenating JSX/string parts around `t()` calls, not by passing a params object.
- **RTL**: use logical Tailwind classes (`start-`/`end-`/`ps-`/`pe-`), never physical (`left-`/`right-`/`pl-`/`pr-`) — physical classes don't flip in RTL and have caused visible layout bugs.
- `resources/js/lib/format.ts`'s `formatNumber`/`formatDate`/`formatDateTime`/`formatMoney`/`formatSyp`/`formatUsd` all force `'en-US'` explicitly, even in Arabic mode — otherwise a browser set to Arabic renders Arabic-Indic digits, which looks broken next to the rest of the UI.
- `html[dir='rtl']` gets a larger base `font-size` in `resources/css/app.css` (`@layer base`) — the Latin font has no Arabic glyphs, so the system fallback renders visibly smaller than the rest of the UI otherwise.

## Windows / Git-Bash environment gotchas

This project is developed on Windows via Git-Bash. Two real bugs came from this environment — watch for both:

1. **Arabic/UTF-8 text passed as an inline `curl` command-line argument gets silently corrupted to literal `?` characters.** Bash itself holds the correct UTF-8 bytes; the corruption happens in the OS-level argv hand-off to `curl.exe`. **Fix**: write the value to a temp file (a real Windows path, not `/tmp/...`) and pass it with `curl --data-urlencode "field@/path/to/file"` instead of `"field=$value"`. `php.exe` does **not** have this problem — its argv handling preserves UTF-8 correctly, so `php -r '...' "$arabicVar"` is safe.
2. `php -r` / `php artisan` print a harmless `Warning: PHP Startup: Unable to load dynamic library 'sodium'...` to **stdout** (not stderr) on this machine. This corrupts naive `$(php ...)` command substitutions — always pipe through `| tail -1` when capturing PHP output into a shell variable.

Other quirks:
- Node.js path resolution is unreliable for `/tmp/...`-style paths from the Bash tool. Copy files into `storage/app/` (a real path under the project) before running Node parsing scripts, then delete them afterward.
- Port 8000 is occupied by an unrelated local project — use another port (8001+) for `php artisan serve` during manual/smoke testing.
- Fetching an Inertia page via `curl` with `X-Inertia: true` but no matching `X-Inertia-Version` header gets a `409` conflict. Simplest fix: do a plain full-page GET (no `X-Inertia` header) and regex out the `<script data-page="app" type="application/json">...</script>` payload instead of trying to hit the partial-reload endpoint directly.
- `fly ssh sftp put <local> <remote>` mangles `/tmp/...`-style paths through Git-Bash's MSYS path conversion on **both** sides — prefix the command with `MSYS2_ARG_CONV_EXCL="*"` and use a real Windows path (e.g. `storage/app/<file>`) for the local side; the remote `/tmp/...` side is fine as-is once conversion is disabled.

## Debugging on the production container

- `fly ssh console` runs as **root**, not `www-data` (confirmed via `id`) — the actual web server (PHP-FPM) always runs as `www-data` (uid/gid 33, per the Dockerfile's `chown -R www-data:www-data storage bootstrap/cache`). **Never run a PHP script via `fly ssh console` that touches `storage/app/**` (or anything else the app writes to at runtime) without first dropping privileges** (`posix_setgid(33)` then `posix_setuid(33)`, while still uid 0) — a root-owned file/directory created this way blocks the real `www-data` process from writing there afterward with a confusing "not writable" error, indistinguishable at first glance from a real permissions bug in the app. (This exact mistake happened once: a debugging script created `storage/app/mpdf` as root, which then broke every real PDF export on the site until the directory was `rm -rf`'d and recreated by a privilege-dropped script.)
- To pull the real exception (not just the access-log line) for a 500 in production: `fly ssh console -a patrol-station -C "grep -a 'production.ERROR' /var/www/html/storage/logs/laravel.log"` — `fly logs` only shows the access-log line (`"GET /index.php" 500`) with no exception detail.
- A `fly ssh console` command's *own* stdout/stderr is genuine even though the process often ends with a spurious `Error: The handle is invalid.` on this Windows client — that trailing line is a PTY-cleanup artifact, not a sign the command itself failed.

## Established verification workflow

Before considering any change done:

1. `./vendor/bin/pint --dirty`
2. `npx tsc --noEmit`
3. `npx eslint <changed paths>`
4. `npx prettier --write <changed paths>`
5. `php artisan test` — healthy baseline is **36 tests, 20 passed, 16 skipped**
6. `npm run build`
7. If a migration was added: back up the real local tenant SQLite file first (`cp database/tenantXXXX.sqlite database/tenantXXXX.sqlite.bak-$(date +%Y%m%d%H%M%S)`), then `php artisan tenants:migrate`
8. Live HTTP smoke test against real local data: temp `php artisan serve --port=<free port>` in the background, curl with a cookie jar + XSRF token dance, exercise the actual endpoints, verify DB state directly via a small PHP script, then **clean up any test data created** (and restore from the step-7 backup if something went wrong)
9. Commit with explicit file paths (never `git add -A`), push, `fly deploy -a patrol-station`
10. After every deploy: `curl -s -o /dev/null -w "%{http_code}" https://patrol-station.fly.dev/login` should return `200`. Schema migrations run automatically on deploy (via the entrypoint script) — no manual `tenants:migrate` step needed in production, though `fly ssh console -a patrol-station -C "php artisan tenants:migrate"` is a safe way to double check (prints "Nothing to migrate" if already applied).
11. After adding/changing a route, run `php artisan wayfinder:generate --with-form` to regenerate the typed TS route helpers under `resources/js/routes` and `resources/js/actions`.

## Patterns worth reusing

- **A plain `<input type="date">` picker + preserving intraday order**: when a form lets you pick just a date (no time), merge it with the current time-of-day rather than collapsing to midnight, so same-day entries still sort correctly: `Carbon::parse($data['date'])->setTimeFrom(now())`. On *update*, only re-time the record if the date actually changed to a different day — otherwise resubmitting the same date on every edit keeps bumping the timestamp and loses the original time.
- **Date-only column uniqueness checks**: `Rule::unique()` compares the raw submitted string against the stored column — but Eloquent's `'date'` cast saves a full datetime string (e.g. `"2026-08-14 00:00:00"`), so a plain `Rule::unique` check silently never matches and lets a real DB constraint violation crash through as a 500. Use a manual `whereDate('column', $value)->exists()` check in the controller instead.
- **Debts** support partial payments (`debt_payments` table, `Debt::recordPayment()`/`remainingAmount()`/`paidAmount()`); Cash Box reads cash movement from `debt_payments`, never from a debt's `amount` directly, so a partial payment shows up on the day it actually happened. Debtors can have one level of sub-debtors (`debtors.parent_id`, no grandchildren) — a parent's Debts view rolls up its children's debts too.
- **Shop** (stock items) purchases use `TransactionType::Purchase` (sales still use `OtherIncome`) — a shop transaction is distinguished from other purchases only by its nullable `shop_item_id`. `TransactionType::Purchase` is also used for Sadcop deposits; it's given the exact same cash-flow treatment as `Expense` everywhere money totals are computed (Cash Box, Statistics, Admin Earnings all `whereIn('type', [Expense, Purchase])`) — it's purely a separate label/filter category, not a different kind of money movement. `transactions.type` has no real DB-level CHECK constraint despite being declared as an `enum()` column in its original migration, so adding a new case needed no schema migration — only a data-backfill migration (`DB::table('transactions')->update(...)`) to recategorize existing rows.
- **Pumps can have multiple fuel types** (`fuel_pump_fuel_type` pivot) but share **one physical counter** — the fuel type of a sale is determined by which *tank* is picked when recording the reading, not by the pump. Don't try to give a multi-fuel pump separate counters per fuel type; that was an explicit design decision.
- Historical-record corrections (editing/deleting a past pump counter reading, inventory entry, Sadcop ledger entry, transferring a debt to another debtor) are consistently gated to `role:admin`.

## Secrets

Never commit credentials to this repo or to CLAUDE.md. Production secrets (R2/Litestream, super admin credentials, etc.) are set via `fly secrets set` and are not recoverable from git history — if a secret was ever pasted into a chat/session transcript, treat it as compromised and rotate it.
