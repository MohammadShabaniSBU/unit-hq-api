# Ops runbooks

Operator-owned database and deployment steps that the application does not perform.

## Metabase reporting role

File: [`metabase-ro-role.sql`](./metabase-ro-role.sql)

Creates `metabase_ro` with `SELECT` on the `analytics` schema only. Point Metabase at this role — never at the application credentials.

```bash
psql "$DATABASE_URL" -v pw="'$(openssl rand -base64 32)'" -f docs/ops/metabase-ro-role.sql
```

Or interactively:

```text
\set pw '''your-strong-password'''
\i docs/ops/metabase-ro-role.sql
```

Rotate by `ALTER ROLE metabase_ro PASSWORD '…'`. Drop and recreate only if grants drift; the SQL is idempotent for grants after a one-time `CREATE ROLE`.

## Agent recall

File: [`agents-recall.md`](./agents-recall.md)

Undo agent-created offers and reservations after `AGENTS_ENABLED=false` stops new turns. Dry-run is the default; commit with `--dry-run=false`. Accepted offers and contracted reservations are reported, never reversed.
