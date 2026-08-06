# S18-01 — `analytics` read schema and reporting role

## Context

A connected Metabase instance is useless until it can query something. The naive move is to
grant it `SELECT` on the application schema and let operators loose in the query builder.
That produces wrong numbers within a week, because every invariant in `09` is a trap when
seen from the query builder:

| Invariant | What breaks against raw tables |
|---|---|
| 19 + D3 — `deposit` and `write_off` are not revenue | `SUM(charges.amount)` overstates revenue |
| Exclusive tax | `charges.amount` is gross; revenue is `net_amount`. Two people build two "revenue" numbers |
| D1 — currency resolves per site | `SUM(amount)` adds euros to pounds silently |
| 5 — availability is derived | Occupancy scanned off `contract_items` instead of `unit_occupancies` |
| Per-site `timezone` | "Revenue in July" resolves against the wrong day boundary |
| 18 — billing snapshots | Cadence read from current `BillingSettings` instead of the contract |
| 3 — ledger is append-only | Reversal rows double-counted |

So the deliverable is a **reporting contract**: a small schema of views that encode those
rules once, owned by Laravel migrations, and a database role that can see nothing else.

**This is not the full report catalogue.** Five views, enough to build the default
dashboards. Extending the catalogue is ongoing work, not a sprint task.

## Scope

**In:**
- `analytics` Postgres schema, created by migration
- Five views encoding the rules above
- One materialized view for daily unit state, plus a refresh command
- `metabase_ro` role with grants limited to `analytics`
- Documentation of the schema as a stable contract

**Out:**
- Any additional views (follow-up, demand-driven)
- Metabase-side dashboards (built by hand, task 07 verification)
- Anything read by application code — nothing in `app/` may query `analytics`

## Schema changes

```sql
-- migration: create_analytics_schema
CREATE SCHEMA IF NOT EXISTS analytics;
```

SQLite has no schemas. Guard the whole migration behind
`DB::getDriverName() === 'pgsql'` exactly as tasks S01-01/02 guard the exclusion
constraints. The test suite asserts the migration is a no-op on SQLite and does not fail.

### Views

`analytics.v_revenue` — one row per revenue-bearing charge, net of reversals:

```sql
CREATE VIEW analytics.v_revenue AS
SELECT
    c.id                AS charge_id,
    ct.id               AS contract_id,
    s.id                AS site_id,
    s.name              AS site_name,
    s.currency          AS currency,
    s.timezone          AS site_timezone,
    c.charge_type,
    c.net_amount,
    c.tax_amount,
    c.amount            AS gross_amount,
    c.due_date,
    (c.period_start AT TIME ZONE s.timezone)::date AS period_start_local,
    (c.period_end   AT TIME ZONE s.timezone)::date AS period_end_local,
    date_trunc('month', c.period_start AT TIME ZONE s.timezone) AS period_month_local
FROM charges c
JOIN contracts ct ON ct.id = c.contract_id
JOIN /* contract → unit → site join per the current schema */ ...
WHERE c.charge_type NOT IN ('deposit', 'write_off')
  AND c.reversal_of_charge_id IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM charges r WHERE r.reversal_of_charge_id = c.id
  );
```

The `NOT EXISTS` clause is the append-only ledger showing up in reporting: a reversed charge
is not revenue, and neither is its reversal. Excluding only rows *with* `reversal_of_charge_id`
set leaves the original counted.

`analytics.v_payments` — mirrors `v_revenue` over `payments` / `allocations`, same reversal
treatment via `reversal_of_payment_id`, carrying `payment_method_type` where present so
collections dashboards can split card / SDD / manual.

`analytics.v_rent_roll` — one row per open occupancy as of today: unit, unit class, site,
contract, contact, current period net amount, currency, `started_on`, `billed_through`.

`analytics.mv_unit_state_daily` — materialized, the date spine:

```sql
CREATE MATERIALIZED VIEW analytics.mv_unit_state_daily AS
SELECT d.day, u.id AS unit_id, u.site_id, u.unit_class_id,
       CASE
           WHEN o.id IS NOT NULL THEN 'occupied'
           WHEN h.id IS NOT NULL THEN h.hold_type
           ELSE 'available'
       END AS state,
       o.contract_id
FROM generate_series(
        (SELECT min(started_on) FROM unit_occupancies),
        CURRENT_DATE, interval '1 day') AS d(day)
CROSS JOIN units u
LEFT JOIN LATERAL (
    SELECT * FROM unit_occupancies o
    WHERE o.unit_id = u.id
      AND o.started_on <= d.day
      AND (o.ended_on IS NULL OR o.ended_on > d.day)
    LIMIT 1
) o ON true
LEFT JOIN LATERAL (
    SELECT * FROM unit_holds h
    WHERE h.unit_id = u.id
      AND h.released_at IS NULL
      AND h.hold_type <> 'overlock'
      AND h.starts_on <= d.day
      AND (h.ends_on IS NULL OR h.ends_on > d.day)
    ORDER BY h.created_at
    LIMIT 1
) h ON true;

CREATE UNIQUE INDEX ON analytics.mv_unit_state_daily (day, unit_id);
CREATE INDEX ON analytics.mv_unit_state_daily (site_id, day);
```

Note `> d.day` on both ends — **exclusive end**, matching S01-01 through S01-03 and the
billing boundary convention. An inclusive end here silently disagrees with the ledger.

The occupancy-wins-over-hold precedence and the earliest-created-unreleased-hold rule are
lifted verbatim from `App\Support\Occupancy\Availability`. If that precedence ever changes,
this view changes with it — say so in a comment in both places.

`analytics.v_pipeline_events` — one row per pipeline transition (contact created, deal
created, stage change, offer sent, offer accepted, reservation created, contract signed)
with `occurred_at`, `site_id` where resolvable, and the subject id, so funnel and conversion
dashboards are a single group-by rather than seven joins.

### Role

```sql
CREATE ROLE metabase_ro LOGIN PASSWORD :'pw' CONNECTION LIMIT 5;
ALTER ROLE metabase_ro SET statement_timeout = '30s';
ALTER ROLE metabase_ro SET idle_in_transaction_session_timeout = '60s';
GRANT USAGE ON SCHEMA analytics TO metabase_ro;
GRANT SELECT ON ALL TABLES IN SCHEMA analytics TO metabase_ro;
ALTER DEFAULT PRIVILEGES IN SCHEMA analytics
    GRANT SELECT ON TABLES TO metabase_ro;
REVOKE ALL ON SCHEMA public FROM metabase_ro;
```

The role is created by a documented operator runbook step, **not** by a migration — the app
does not own database roles and should not hold the privilege to create them. Ship the SQL
in `docs/ops/` and reference it from the connection screen's help text.

## Implementation notes

- Views are defined in migrations with `CREATE OR REPLACE VIEW`. Changing a view is a new
  migration, never an edit to a shipped one — same rule as every other migration.
- `php artisan analytics:refresh` runs `REFRESH MATERIALIZED VIEW CONCURRENTLY
  analytics.mv_unit_state_daily`. `CONCURRENTLY` requires the unique index above and does not
  block readers. Schedule daily, early, in the site-agnostic small hours; log start/finish as
  Tier-1 `SystemEvent` rows (`analytics.refresh.started` / `.committed`).
- At ~500 units and three years the spine is roughly 550k rows. Assert the refresh completes
  in under 30s on the seeded database; if it does not, partition by year before adding views,
  not after.
- **Currency is never summed across sites.** Every monetary view exposes `currency` as a
  column and the documentation states that any aggregate must group by it. There is no
  conversion layer and this sprint does not add one.

## API surface

None. Nothing in `app/` reads this schema.

## Panel surface

None, except help text on the connection screen (task 06) pointing at the runbook.

## Invariants

> **5. Derived state only** — never store `is_available`, balance owed, overdue flags, or
> unallocated credit as columns.

`mv_unit_state_daily` does **not** breach this, for the same reason `unit_occupancies` does
not: it is a refreshed projection of facts held in `analytics`, consumed only by external
reporting, and never read by application code or by a write path. Add this clarification to
invariant 5 alongside the fact-table clarification from S01-01, or a future session will
"fix" it.

> **10. Money is `NUMERIC(10,2)`** — views must not cast money to float for convenience.

> **19. Deposit charges are not revenue** — extended to `write_off` per D3, encoded in
> `v_revenue`'s `WHERE` clause rather than left to the dashboard author.

## Acceptance criteria

- [ ] Migration runs clean on Postgres and is a verified no-op on SQLite.
- [ ] `metabase_ro` can `SELECT` every view in `analytics` and **cannot** read any table in
      `public` — assert both directions.
- [ ] `SELECT sum(net_amount) FROM analytics.v_revenue` on the seeded database equals the
      figure the native revenue report produces for the same window and currency.
- [ ] Occupancy for today from `mv_unit_state_daily` equals the Unit Class matrix figure for
      every site × class cell.
- [ ] A reversed charge appears in neither `v_revenue` nor the reversal's own row.
- [ ] `analytics:refresh` completes under 30s on the seeded database and is scheduled daily.
- [ ] `grep -r "analytics\." app/` returns nothing.
- [ ] Schema documented in `docs/` as a stable contract with a note that changes are
      additive.

## Tests required

| Test | Asserts |
|---|---|
| `AnalyticsSchemaTest::migration_noop_on_sqlite` | Suite still runs on SQLite |
| `Pgsql/AnalyticsGrantsTest::reporting_role_cannot_read_public` | Grant isolation, both directions |
| `Pgsql/RevenueViewTest::excludes_deposit_and_write_off` | Invariant 19 + D3 |
| `Pgsql/RevenueViewTest::excludes_reversed_and_reversal_rows` | Invariant 3 in reporting |
| `Pgsql/RevenueViewTest::matches_native_revenue_report` | Parity with the app's own number |
| `Pgsql/UnitStateDailyTest::exclusive_end_boundary` | Move-out and move-in on the same day |
| `Pgsql/UnitStateDailyTest::overlock_does_not_mask_occupied` | Precedence matches `Availability` |
| `Pgsql/UnitStateDailyTest::matches_unit_class_matrix_today` | Parity with the operational read |
| `AnalyticsRefreshCommandTest::completes_within_budget` | Refresh performance on the seed |
