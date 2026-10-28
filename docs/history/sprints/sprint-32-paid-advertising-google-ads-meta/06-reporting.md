# S32-06 — Paid acquisition report & analytics views

## Native report `paid-acquisition`

Register in `App\Support\Insights\NativeReports` (section `operations`, next to
`funnel`, icon `i-lucide-megaphone`). Seeded system row via `NativeReportSync`,
labels in `insights.reports.paid_acquisition.*` (en/es/fr). Honours the global
site selector (`site_scope_mode = inherit`).

**Harvest principle:** live bounded queries over facts. No rollup table.

### Filters
`from` / `to`, site set, platform (all | google_ads | meta), attribution window
(default 90 days, options 30 / 60 / 90).

### Definitions (add to `docs/report-definitions.md` → new section "Paid acquisition / Adquisición de pago")

| Term (EN) | Term (ES) | Definition |
|---|---|---|
| Spend | Inversión | Σ `ad_spend_daily.cost` for campaigns mapped to the site set, `spend_date` in range (account-local dates). Grouped by currency. |
| Paid leads | Leads de pago | Deals created in range whose contact's **attributed touch** is paid (non-null `platform`) on the selected platform(s). |
| Paid move-ins | Altas de pago | Contracts with `signed_at` in range whose contact's attributed touch is paid. Site = contract site. |
| Attributed touch | Contacto atribuido | Latest touch with non-null `platform`, `occurred_at` ≤ the deal's `created_at` and ≥ `created_at − window`. Computed per query (A3). |
| Cost per lead | Coste por lead | Spend ÷ paid leads, per currency, per site. `—` when leads = 0. |
| Cost per move-in | Coste por alta | Spend ÷ paid move-ins, same rules. |
| ROAS (first period) | ROAS (primer periodo) | Σ first-period revenue net of paid move-ins ÷ spend. **Only shown when both are in the same currency**; otherwise `—` with a tooltip. Invariants 30/35. |
| Unmapped spend | Inversión sin asignar | Spend on campaigns with no `site_id`. Shown as its own row, never spread across sites. |

**Timezone caveat**, printed in the report footer: spend dates are civil dates in
the ad account's timezone. Leads and move-ins use the site's timezone (D8). At
day granularity across zones the edges can shift by one day. This is documented,
not corrected.

### Layout
1. KPI cards: spend, paid leads, paid move-ins, cost per lead, cost per move-in,
   each per currency.
2. Table by site × platform: spend, leads, move-ins, CPL, cost per move-in, ROAS.
3. Table by ad campaign: same columns, plus a mapping status chip.
4. Trend: weekly spend vs paid move-ins.
5. CSV export following the existing locale rules (`;` + `,` for `es`, UTF-8 BOM).

Endpoint: `GET /api/insights/paid-acquisition` (same controller pattern and
permission check as `funnel`, plus `advertising.view`).

## Funnel report tweak

The existing funnel report gains an optional filter, `acquisition = all | paid | organic`,
using the same attributed-touch definition. Source attribution notes in
`report-definitions.md` now say: `contacts.source` stays the coarse origin; paid
attribution comes from touches.

## `analytics` views (additive, invariant: never edit shipped views)

- `analytics.v_ad_spend_daily`: campaign, account provider, mapped `site_id`,
  `unit_class_id`, `spend_date`, `impressions`, `clicks`, `cost`, `currency`,
  `account_timezone`.
- `analytics.v_marketing_touches`: `touch_id`, `contact_id`, `occurred_at`,
  `site_id`, `platform`, `utm_source`, `utm_medium`, `utm_campaign`. **No
  `click_ids`, no `landing_url`, no `visitor_key`.** BI never needs them, and
  they are redaction-sensitive.
- `analytics.v_ad_conversion_deliveries`: event key, account provider, status,
  skip_reason, `occurred_at`, `processed_at`, `value`, `currency`.

`metabase_ro` already reads the whole `analytics` schema, so no grant changes.

## Acceptance criteria

- [ ] Fixture: 2 sites, 2 accounts (EUR, GBP), mapped and unmapped campaigns.
      Totals reconcile to raw rows. No figure sums EUR with GBP.
- [ ] A contact whose only touch is 120 days before deal creation is organic at
      the 90-day window and paid at none of the options.
- [ ] Unmapped spend appears once, never inside a site row.
- [ ] ROAS shows `—` when spend and revenue currencies differ.
- [ ] Changing the window changes counts without any write.
- [ ] `v_marketing_touches` exposes no click id column (schema test).
