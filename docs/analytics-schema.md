# Analytics schema (reporting contract)

Stable, additive contract for external BI (default: Metabase). Owned by Laravel
migrations under Postgres; SQLite migrations are a no-op.

**Nothing in `app/` may query `analytics.*`.** Application code owns the DDL and
the `analytics:refresh` command only. Consumers are external reporting tools via
the `metabase_ro` role ([ops runbook](ops/metabase-ro-role.sql)).

Changes are **additive**: new views/columns may appear; existing view names and
column meanings do not change in place. Altering a view ships as a new migration
(`CREATE OR REPLACE VIEW`), never an edit to a shipped migration file.

## Currency rule

Every monetary view exposes a `currency` column. **Never sum amounts across
currencies.** There is no conversion layer.

## Catalogue

### `analytics.v_revenue`

One row per revenue-bearing charge, net of reversals.

| Column | Notes |
|--------|--------|
| `charge_id`, `contract_id`, `site_id`, `site_name` | Identifiers |
| `currency` | Ledger `charges.currency` |
| `site_timezone` | Site civil timezone (context) |
| `charge_type` | Excludes `deposit`, `write_off`, `refund` (`ChargeType::isRevenue`) |
| `net_amount`, `tax_amount`, `gross_amount` | Exclusive tax; revenue measure is `net_amount` |
| `due_date`, `period_start_local`, `period_end_local`, `period_month_local` | Period dates are site-local civil dates as stored |

Reversal filter: rows with `reversal_of_charge_id` set are excluded, and so are
originals that have a child reversal (append-only ledger — invariant 3).

### `analytics.v_payments`

Mirrors revenue treatment over `payments`: excludes reversal children and
reversed originals. Exposes `payment_method_type` (ledger `payments.method`).

### `analytics.v_rent_roll`

One row per open occupancy as of `CURRENT_DATE`: unit, class, site, contract,
contact, current open unit-item price amount (`current_period_net_amount`),
currency, `started_on`, `billed_through`.

### `analytics.mv_unit_state_daily`

Materialized date spine × unit. State is `occupied`, a non-overlock `hold_type`,
or `available`. Precedence and exclusive ends match
`App\Support\Occupancy\Availability`. Refresh with:

```bash
php artisan analytics:refresh
```

Uses `REFRESH MATERIALIZED VIEW CONCURRENTLY` outside transactions. Scheduled
daily. Emits Tier-1 `analytics.refresh.started` / `.committed` SystemEvents.

At ~500 units and three years the spine is roughly 550k rows; refresh should
complete under 30s on a seeded database.

Clarification vs invariant 5: this is an external reporting projection, never
read by application write or read paths.

### `analytics.v_pipeline_events`

One row per funnel transition:

| `event_type` | Source |
|--------------|--------|
| `contact.created` | `contacts.created_at` |
| `deal.created` | `deals.created_at` |
| `deal.stage_changed` | `activity_log` (`description = deal.stage_changed`) |
| `offer.sent` / `offer.accepted` | `offers.sent_at` / `accepted_at` |
| `reservation.created` | `reservations.created_at` |
| `contract.signed` | `contracts.signed_at` |

Columns: `event_type`, `occurred_at`, `site_id` (nullable when unresolved),
`subject_type`, `subject_id`.

## Role

See [docs/ops/metabase-ro-role.sql](ops/metabase-ro-role.sql). The role can
`SELECT` everything in `analytics` and must not read `public` business tables.
