# Environment contract

All host-specific detail lives here. Nothing in `README.md` or any scenario names a port, container, alias, or hostname. Resolve the four values below before running anything. Everything downstream refers to them by name only.

## Contract

| Name | Meaning |
|---|---|
| `PANEL_URL` | Panel origin. Scenario routes are relative (`/billing/delinquency`) and are appended to this. |
| `API_URL` | API origin. Used for health checks only. |
| `DB_DRIVER` | `pgsql` or `sqlite`. Decides which scenarios are runnable (`requires_db`) and which reset recipe applies. |
| `LOGIN_PASSWORD` | Password for every seeded employee. Always `password` after `demo:seed`. |

Postgres is the reference environment. SQLite is a permitted fallback — see [SQLite caveat](#sqlite-caveat).

## Discovery (do this before assuming anything)

1. Read `unit-hq-api/.env` for `DB_CONNECTION` → `DB_DRIVER`, `DB_DATABASE`, `APP_URL`, and `PANEL_URL` (if present).
2. Read `unit-hq-panel/.env` for `NUXT_PUBLIC_API_BASE_URL` → `API_URL`. If that file is missing, fall back to `unit-hq-panel/.env.example`, then to `APP_URL` from the API env.
3. If `PANEL_URL` is unset in the API env, fall back to `unit-hq-api/.env.example` (`PANEL_URL`).
4. Probe `API_URL` (Laravel health is `GET {API_URL}/up`) and `PANEL_URL`. If both respond, use them and **start nothing**.
5. Only if a probe fails, [bootstrap](#bootstrap-when-nothing-is-running).

Do not restart a server that is already responding.

## Bootstrap, when nothing is running

From a bare OS with both repos cloned and PHP / Composer / Node / bun available:

```bash
# unit-hq-api — copies .env.example if needed, generates a key, migrates
composer setup
php artisan serve

# unit-hq-panel — in a second process
bun install
bun run dev

# E2E / browser-agent seed (10 days, 2 sites, cast only)
php artisan demo:seed --fresh --compact

# Presenter world only — not for this E2E suite (budget: under 10 minutes; ~426 days)
# php artisan demo:seed --fresh
```

`composer setup` leaves `DB_CONNECTION=sqlite` unless `.env` is already present. After serve + panel are up, re-run discovery so `PANEL_URL` and `API_URL` match what is actually listening.

Whether `demo:seed` completes cleanly on SQLite is unverified. If it fails, install Postgres locally, set `DB_CONNECTION=pgsql` (and the matching `DB_*` keys), migrate, and seed again. Do not treat a failed SQLite seed as a product bug until the same command has been tried on Postgres.

## Example profiles

These are illustrations of what discovery might observe. They are **not** requirements. Pick a profile from what the `.env` files and probes say, never from this list.

**Developer machine (example).** API already running (often in a container), panel already running, `DB_CONNECTION=pgsql`. Container CLIs or local aliases may wrap `php artisan`. Do not start or restart those processes. Seed and artisan commands go through whatever wrapper this host already uses.

**Bare VM (example).** Fresh clone, nothing listening. `composer setup` defaults to SQLite. `php artisan serve` and `bun run dev` are started only because the probes failed. File-copy reset (below) applies.

## Reset recipes

Automated reset tooling does not exist yet. Until it does, a mutating scenario (`mutates: true`) needs a manual reset between runs. Prefer a snapshot restore over a full re-seed.

### SQLite — file copy

After a successful `demo:seed --fresh --compact`, copy the seeded file aside. `DB_DATABASE` in `.env` is the path if set; otherwise Laravel's default is `database/database.sqlite`.

```bash
# once, after seed
cp "$SEEDED_SQLITE" "$SEEDED_SQLITE.snapshot"

# before each mutating scenario
cp "$SEEDED_SQLITE.snapshot" "$SEEDED_SQLITE"
```

No extra privileges. This is the portable recipe.

### Postgres — template database

`CREATE DATABASE … TEMPLATE` is a file-level copy and is near-instant, but it requires **zero** active connections to the source. Read `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` from `unit-hq-api/.env`. The names below are placeholders for those values.

```bash
# once, after seed — terminate sessions, then snapshot
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d postgres \
  -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$DB_DATABASE' AND pid <> pg_backend_pid();"
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d postgres \
  -c "DROP DATABASE IF EXISTS ${DB_DATABASE}_snapshot;"
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d postgres \
  -c "CREATE DATABASE ${DB_DATABASE}_snapshot TEMPLATE $DB_DATABASE;"

# before each mutating scenario
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d postgres \
  -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$DB_DATABASE' AND pid <> pg_backend_pid();"
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d postgres \
  -c "DROP DATABASE $DB_DATABASE;"
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d postgres \
  -c "CREATE DATABASE $DB_DATABASE TEMPLATE ${DB_DATABASE}_snapshot;"
```

If terminating sessions is not acceptable on this host, fall back to `php artisan demo:seed --fresh --compact`.

Non-mutating scenarios (`mutates: false`) share one seeded database and do not need a reset between them.

## SQLite caveat

Active migrations wrap every Postgres-only construct in `if (DB::getDriverName() === 'pgsql')`. SQLite therefore migrates and the app runs, but it silently drops database-level integrity that several invariants depend on:

- `EXCLUDE USING gist` overlap constraints on `prices` and `contract_items`
- partial unique index on `offer_options` (one selected option per offer)
- `prices_scope_shape` check

A scenario that proves "two options cannot be selected on one offer" would **pass on SQLite for the wrong reason**. Any such scenario carries `requires_db: pgsql` and must be labelled **skipped** on SQLite, never passed. None of the smoke scenarios need this; the field exists so later ones can be honest.
