# Pricing Domain

## Price — the shared reusable model

Every amount expressing what something costs lives in `prices` and is referenced by `price_id`. Currency lives on the price row. Full model: `roadmap/sprint-02-pricing-model/architecture-pricing.md`.

**Fields:**

| Field | Type / rule |
|---|---|
| `amount` | `NUMERIC(10,2)` — **floats are never used for money** |
| `currency` | **Sole authority** for denomination (ISO 4217 allowlist, uppercase). Site and org currency are form prefill defaults only — never read at transaction time, in a resource, or in a rollup (D1 / invariant 29). |
| `scope` | `catalogue` (class/insurance rate timeline) or `contract` (negotiated one-off; no windows) |
| `priceable_type` / `priceable_id` | Owner morph — `unit_class_rate` or `insurance_rate` |
| `effective_from` / `effective_to` | Catalogue windows only (`[)` exclusive end). Contract-scoped prices must leave both NULL. |
| `created_by` | audit reference |

**Dropped:** `billing_period` — contract cadence comes from org `BillingSettings`, snapshotted onto the contract.

## Immutability rule (hard invariant)

**Never `UPDATE` a price amount** (or currency / scope / ownership). A catalogue rate change is always:

1. Close the current catalogue price by setting `effective_to` (once).
2. Insert a successor `prices` row owned by the **same** static junction.

Junctions (`unit_class_rates` / `insurance_rates`) are created once per pairing and are never versioned. Contract timing for signed rates lives on `contract_items` versions (S02-00), not on price windows.

A customer-facing quote names the immutable `prices` row (`price_id`). A later
agent offer for that class must carry `quoted_price_id` or be refused; a
superseded row refuses (invariant 66). Mechanics: `14-ai-agents.md`.

## TaxRate — exclusive tax catalogue

Effective-dated and immutable, **mirroring prices**.

| Field | Rule |
|---|---|
| `name` | Display name |
| `code` | Stable identity across versions (e.g. `vat`, `ipt`) |
| `rate` | `NUMERIC(5,2)` percent |
| `jurisdiction` | `NULL` (applies anywhere) or ISO 3166-1 alpha-2 with optional ISO 3166-2 subdivision (`ES`, `ES-CN`, `FR`). Validated on write (D2 / invariant 33). |
| `is_default` | At most one `true` (partial unique index on Postgres) |
| `effective_from` / `effective_to` | Version window; `effective_to NULL` = current |

**Never `UPDATE` `rate` in place.** PATCH creates a new version with the same `code`, closes the previous row's `effective_to`, in one transaction. Facility activity: `rate.tax.versioned` on `LogChannel::Facility`.

### Product defaults

- `unit_classes.tax_rate_code` and `insurances.tax_rate_code` (nullable strings) point at a tax code.
- At contract item create / transfer re-snapshot, resolution is centralised in
  `App\Support\Fiscal\TaxResolver` (S03-05 / D2):
  1. Explicit `tax_rate_id` override from the request (if any) — no jurisdiction
     filtering; the operator's choice is logged
  2. Else product `tax_rate_code` → among active versions of that code, prefer
     `jurisdiction` equal to the site's country (`country_id` → `countries.code`),
     else the `NULL`-jurisdiction (universal) version, else **fail loudly**
     (`tax_unresolvable_for_jurisdiction`, 422) — never a wrong country's rate
  3. Else org default rate (`is_default`), same preference chain on that code
  4. Else no tax (`0%`) — only when neither product nor default names a code

Matching is exact country-code equality; no region / subdivision hierarchy until a
real case demands it. Snapshots land on `contract_items.tax_rate_id` +
`tax_rate_snapshot`, and on each first-period charge.

### Tax basis (exclusive)

Item `amount` / charge `net_amount` is **net**.  
`tax = round(net × rate/100, 2)`; `gross = net + tax`.  
See `05-billing-ledger.md` for charge generation.

### API (panel Settings → Tax rates)

- `GET /api/tax-rates` — current versions (`effective_to IS NULL`); `?code=` returns history for that code
- `GET /api/tax-rates/options` — `{ value, label, code }` for selects
- `POST /api/tax-rates` — create
- `PATCH /api/tax-rates/{id}` — new version
- `POST /api/tax-rates/{id}/default` — set default

### API (panel Settings → Facility → Discounts)

- `GET /api/discounts/options` — `{ value, label }` for selects
- `GET /api/discounts` — list
- `POST /api/discounts` — create
- `GET /api/discounts/{id}` — show
- `PATCH /api/discounts/{id}` — update
- `POST /api/discounts/{id}/archive` — archive
- `POST /api/discounts/{id}/unarchive` — unarchive

(See DISC-03 above for `GET /api/discounts/{id}/resolve`.)

## Insurance

- `Insurance` (plan) follows the **same pattern as UnitClass pricing**: a static `InsuranceRate` junction at the **Site (Center) level**; catalogue prices morph-own that junction. *(Note: `insurance_rates.site_id` is nullable in the schema — latent capacity for a future org-wide insurance rate — but `InsuranceController` currently requires `site_id` on writes, so this isn't exercised yet.)*
- Insurance is **facility-scoped**, not per-building (there is no building layer).
- Optional `tax_rate_code` default for IPT / similar.
- Sold on contracts as a `ContractItem` line (polymorphic — see `04-crm-pipeline.md` / `05-billing-ledger.md`).

## Discount

Admin-defined catalogue rows operators *pick* — never free-typed at a counter.
Archive-only (`archived_at`); archived rows vanish from pickers but stay resolvable
for provenance. Settings → Facility → Discounts. Decisions: **D-DISC** in
`10-open-decisions.md`; invariant 41 in `09`.

| Kind | `params` | Notes |
|---|---|---|
| `percent` | `{ "percent": "20.00" }` | `0 < percent < 100`, scale-2 string. `tracks_rate_changes` (default true). |
| `free_time` | `{ "tiers": [ { "min_commitment_weeks", "free_weeks" }, … ] }` | Non-empty; both fields strictly increasing; `free_weeks < min_commitment_weeks` per tier. |

- **`applies_to`** defaults to `unit` (v1 — insurance untouched).
- **Cadence alignment** (non-blocking): each `free_weeks × 7` checked against org
  billing cadence length; misaligned tiers save with `alignment_warnings`.
- **Compile-at-signing (DISC-01):** a picked discount materializes as contract-
  scoped `contract_items` price versions (+ `discount_id` provenance). Billing
  never branches on discount presence. `convert-preview` returns the same
  compiled `discount_schedule` the convert path writes.
- **Lifecycle (DISC-02):**
  - Rate change: API `new_amount` is the **new list**. Tracking percent recomputes
    `round2(list × (1−p))`; non-tracking writes plain list; free-time open tip uses
    `new_list × (amount/base_rate)` (the multiplier is the promise). Pre-written free
    windows are left alone. Provenance (`discount_id`, `base_rate`) carries forward.
  - Removal: `DELETE /api/contracts/{id}/discount` with required `reason` → list price
    from the **next period boundary**; future free segments collapse; linkage closed via
    `discount_removed_at/by/reason`; Tier-3 `contract.discount_removed`.
  - Transfer `retain_rate` keeps the discounted price; `destination_rate` closes
    provenance with reason `transfer` (new unit = new deal).
- **Surfaces (DISC-03):**
  - `GET /api/discounts/{id}/resolve` — tier resolution + `promo_line` + optional
    `discount_schedule` for the offer-option and walk-in pickers (honest
    `no_stay_length` warning when free-time has no commitment).
  - Public token offer (`GET /api/offers/token/{token}`) eager-loads discount and
    returns localized `promo_line` / `discount_resolution` per option (contact locale).
  - Panel: offer option + walk-in discount select; convert-preview schedule rows;
    contract billing card chip / computed schedule / remove modal. i18n `discounts.*`.
- Seeded menu: **10% off**, **20% off**, and **Long-stay promo**
  (`4→2`, `8→4`, `12→6` weeks).

## Tables

| Table | Purpose |
|---|---|
| `prices` | Immutable price rows |
| `unit_class_rates` | Site × UnitClass → price |
| `insurances` | Insurance plans (+ optional `tax_rate_code`) |
| `insurance_rates` | Site × Insurance → price |
| `tax_rates` | Immutable exclusive tax versions by `code` |
| `discounts` | Archive-only percent / free_time catalogue |
| `unit_classes` | Also carries optional `tax_rate_code` |
