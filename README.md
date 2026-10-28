# Keevaris API

Keevaris is a mono-tenant self-storage operations platform: one company, one database, one country per deployment. This repo is the Laravel API. It covers facility management, the leasing pipeline, the billing ledger, and payments scoped to legal entities.

## Repos

Three git repos sit side by side. This directory is not a monorepo.

| Repo | Role |
|---|---|
| `unit-hq-api` | This API (Laravel 13, PHP 8.4) |
| `unit-hq-panel` | Operator panel (Nuxt 4) |
| `keevaris-voice` | Voice media bridge |

## Local setup

`docker-compose.yml` runs the `app` service from `webdevops/php-nginx:8.4-alpine` (it does not `build` the `Dockerfile`). The repo is mounted at `/app`. Published ports are `8000` (HTTP) and `8001` (Reverb). Traefik labels use the host `test2.keevaris.local`.

The compose file joins two **external** networks, `traefik-network` and `postgres-network`. This repo does not create them.

`.env.example` sets `DB_CONNECTION=sqlite`, `KEEVARIS_COUNTRY=ES`, and `PANEL_URL=http://localhost:3000`. The running app reads `.env`, which compose does not create.

<!-- TODO(owner): confirm the first-run sequence (who creates the external networks, when `.env` is copied, and whether local Docker uses SQLite or the external Postgres). -->

The `Dockerfile` is a separate production-style image (`APP_ENV=production`, `composer install`, `php artisan storage:link`). Compose does not use it.

Day-to-day commands, including "do not start the backend yourself", are in `AGENTS.md`.

## Tests

Postgres-backed, via `docker-compose.test.yml`. The entrypoint is `.docker/test.sh`: install Composer deps if `vendor/` is missing, copy `.env.example` to `.env` if needed, generate `APP_KEY`, then `php artisan test`.

```bash
docker compose -f docker-compose.test.yml run --rm test
docker compose -f docker-compose.test.yml run --rm test path/to/test_file
```

Run the related tests, not the full suite, unless you mean to.

## Demo world

Opt-in living facility seed (~350 contacts, 14 months of simulated history). Refuses on production.

```bash
php artisan demo:seed --fresh
```

- Prints a presenter sheet and writes `storage/demo-script.md` (cast index, 15-minute tour, numbers that must match).
- `DEMO_SEED` (default `424242`) varies the crowd only; the named cast is fixed.
- `--cast-only` skips crowd generation (debug).
- Design and matrix: [`docs/domain/demo-seeders/`](docs/domain/demo-seeders/).

Per-sprint `php artisan db:seed` fixtures stay separate for the test suite — do not entangle them with the demo pipeline.

## Where to start reading

1. `AGENTS.md` — commands and the rules that override convenience
2. `docs/STATUS.md` — what is shipped and what is still a gap
3. `docs/domain/00-overview.md` — product map
4. `docs/reference/09-conventions-and-invariants.md` — full invariant list, before you change billing, pricing, contracts, or auth

## Tasks

Work is a task file in `docs/tasks/active/`, copied from [`docs/tasks/_template.md`](docs/tasks/_template.md). A task is not done until the domain docs it affects are updated. Finished files move to `docs/history/tasks/`.
