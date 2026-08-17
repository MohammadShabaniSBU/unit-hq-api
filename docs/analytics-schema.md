# Analytics schema (reporting contract)

Stable, additive contract for external BI (default: Metabase). Owned by Laravel
migrations under Postgres; SQLite migrations are a no-op.

**Nothing in `app/` may query `analytics.*`.** Application code owns the DDL and
the `analytics:refresh` command only. Consumers are external reporting tools via
the `metabase_ro` role ([ops runbook](ops/metabase-ro-role.sql)).

Changes are **additive**: new views/columns may appear; existing view names and
column meanings do not change in place. Altering a view ships as a new migration
(`CREATE OR REPLACE VIEW`), never an edit to a shipped migration file.

**Exception (2026-08-17):** `enabled` and `area` were added to
`analytics.mv_unit_state_daily` by editing
`2026_08_06_100000_create_analytics_schema.php` in place because no environment
had run that migration. This release **requires `migrate:fresh`**. Additive view
changes after this still ship as new migrations. A materialized-view column
change on a live database is DROP + CREATE plus recreating
`mv_unit_state_daily_day_unit_uidx` and `mv_unit_state_daily_site_day_idx`.

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
or `available`. Also projects `enabled` (`units.enabled`) and `area`
(`unit_classes.size`). Precedence and exclusive ends match
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

## Known additive follow-ups

These are gaps, not defects in the objects that ship:

- **`mv_unit_state_daily`** — economic occupancy still needs catalogue-as-of-day
  (class×site price). Unit and area occupancy of enabled units are provisioned.
- **`v_pipeline_events`** — add `deal_id` correlation and an `offer.viewed`
  event so conversion (not just volumes) can be provisioned.
- **New views the `BLOCKED` list needs:** `v_open_charges` (ageing),
  `v_allocations` (collections / daily-close), `v_deposit_liability`,
  `v_movement_events`.

## Role

See [docs/ops/metabase-ro-role.sql](ops/metabase-ro-role.sql). The role can
`SELECT` everything in `analytics` and must not read `public` business tables.
