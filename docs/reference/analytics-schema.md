# Analytics schema (reporting contract)

Stable, additive contract for external BI (default: Metabase). Owned by Laravel
migrations under Postgres; SQLite migrations are a no-op.

**Nothing in `app/` may query `analytics.*`.** Application code owns the DDL and
the `analytics:refresh` command only. Consumers are external reporting tools via
the `metabase_ro` role ([ops runbook](../ops/metabase-ro-role.sql)).

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

### `analytics.v_consent_state`

One row per (contact, channel, subscription list): the latest `consent_events`
row, ordered by `occurred_at` then `id`. Derived state (invariants 5 and 75),
never stored, and never read by `app/`.

| Column | Notes |
|--------|--------|
| `contact_id`, `channel`, `subscription_list_id` | Grain |
| `list_key` | `subscription_lists.key` (`metabase_ro` cannot read `public`) |
| `action` | `granted`, `pending`, or `withdrawn` |
| `source` | How the latest event was recorded |
| `occurred_at` | Timestamp of that event |

`evidence` is absent. It holds IP and wording-adjacent payload, and redaction
nulls it on the ledger. Current membership is `action = granted`. Grants and
withdrawals over time are `analytics.v_consent_events`, not this view.

### `analytics.v_consent_events`

One row per `consent_events` row. Derived (invariants 5 and 75). `app/` does
not query it. List growth (grants minus withdrawals per list) reads this view.

| Column | Notes |
|--------|--------|
| `id` | Ledger row |
| `contact_id`, `channel`, `subscription_list_id` | Who, channel, list |
| `list_key` | `subscription_lists.key` |
| `action` | `granted`, `pending`, or `withdrawn` |
| `source` | How the event was recorded |
| `occurred_at` | Timestamp of that event |

`evidence` is absent, same reason as `v_consent_state`. `pending` is neither
a grant nor a withdrawal.

### `analytics.v_campaigns`

One row per campaign. Derived. No stored counters (invariant 84). A campaign
may pin one template version per channel, so template columns are split by
channel. Channel-level recipient rows are `v_campaign_recipients`.

| Column | Notes |
|--------|--------|
| `id`, `type`, `name`, `status` | `campaign`, `newsletter`, or `announcement` |
| `subscription_list_id`, `list_key` | Null for an announcement |
| `site_ids` | JSON array. Empty means company-wide |
| `approved_at` | Null when approval was not required |
| `window_start`, `window_end` | Earliest `campaign_sends.scheduled_at`, latest `completed_at` |
| `email_template_family_id`, `email_template_version_id` | Email send pin. Null when that channel is absent |
| `sms_template_family_id`, `sms_template_version_id` | SMS send pin. Null when that channel is absent |

### `analytics.v_campaign_recipients`

One row per `campaign_recipients` row. Delivery flags are derived from
`messages.delivery_events` (`status` of `delivered`, `bounced`, `opened`,
`clicked`, or `spam` for complained). Address, body, and `error` are absent.

| Column | Notes |
|--------|--------|
| `campaign_id`, `campaign_send_id`, `contact_id` | Grain |
| `channel` | `email` or `sms` |
| `template_family_id`, `template_version_id` | Pin on that send |
| `status`, `skip_reason`, `processed_at` | Recipient outcome |
| `delivered`, `bounced`, `opened`, `clicked`, `complained` | Boolean. False when no message or no matching event |

### `analytics.v_ad_spend_daily`

One row per campaign per civil spend date. Derived from the restated spend cache. Cost stays in the ad account currency.

| Column | Notes |
|--------|--------|
| `campaign_id`, `campaign_name` | Synced campaign |
| `provider` | `google_ads` or `meta` |
| `site_id`, `unit_class_id` | Operator mapping. Null `site_id` is unmapped spend |
| `spend_date` | Civil date in the ad account timezone |
| `impressions`, `clicks`, `cost`, `currency` | `cost` is `NUMERIC(12,2)`, not ledger money |
| `account_timezone` | Timezone that owns `spend_date` |

### `analytics.v_marketing_touches`

One row per touch. Attribution is not stored here. BI must compute it.

| Column | Notes |
|--------|--------|
| `touch_id`, `contact_id`, `occurred_at`, `site_id` | Fact |
| `platform` | Null when the visit was not a paid click |
| `utm_source`, `utm_medium`, `utm_campaign` | As captured |

`click_ids`, `landing_url`, and `visitor_key` are absent. They are redaction-sensitive and BI does not need them.

### `analytics.v_ad_conversion_deliveries`

One row per delivery attempt. No identifier values and no provider payload.

| Column | Notes |
|--------|--------|
| `event_key` | `lead`, `reservation`, or `move_in` |
| `provider` | Ad account provider |
| `status`, `skip_reason` | Outcome. `skip_reason` is null when the row was sent |
| `occurred_at` | Event time |
| `processed_at` | Delivery attempt time |
| `value`, `currency` | Conversion value from the event. Both null, or both set |

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

See [docs/ops/metabase-ro-role.sql](../ops/metabase-ro-role.sql). The role can
`SELECT` everything in `analytics` and must not read `public` business tables.
