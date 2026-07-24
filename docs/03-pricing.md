# Pricing Domain

## Price — the shared reusable model

Any entity that needs pricing references a `price_id`. Current consumers: `UnitClassRate`, `InsuranceRate` (a.k.a. InsurancePlanRate). Future: anything sellable.

**Fields:**

| Field | Type / rule |
|---|---|
| `amount` | `NUMERIC(10,2)` — **floats are never used for money** |
| `currency` | ISO code |
| `billing_period` | Legacy label on the price row (e.g. monthly). **Contract cadence** comes from org `BillingSettings` (`default_billing_interval` + count), snapshotted onto the contract — not from this column. |
| `effective_from` | start of validity |
| `effective_to` | `NULL` when current |
| `created_by` | audit reference |

## Immutability rule (hard invariant)

**Never `UPDATE` a price amount.** A rate change is always:

1. Insert a new `prices` row.
2. Insert a new junction row (`unit_class_rates` / `insurance_rates`) pointing at it.
3. Close the old price by setting `effective_to`.

History is preserved by construction. This applies to every priced entity.

## TaxRate — exclusive tax catalogue

Effective-dated and immutable, **mirroring prices**.

| Field | Rule |
|---|---|
| `name` | Display name |
| `code` | Stable identity across versions (e.g. `vat`, `ipt`) |
| `rate` | `NUMERIC(5,2)` percent |
| `jurisdiction` | Optional short code |
| `is_default` | At most one `true` (partial unique index on Postgres) |
| `effective_from` / `effective_to` | Version window; `effective_to NULL` = current |

**Never `UPDATE` `rate` in place.** PATCH creates a new version with the same `code`, closes the previous row's `effective_to`, in one transaction. Facility activity: `rate.tax.versioned` on `LogChannel::Facility`.

### Product defaults

- `unit_classes.tax_rate_code` and `insurances.tax_rate_code` (nullable strings) point at a tax code.
- At contract item create, resolution order:
  1. Explicit `tax_rate_id` override from the request (if any)
  2. Else product `tax_rate_code` → active version at move-in
  3. Else org default tax rate
  4. Else no tax (`0%`)

Snapshots land on `contract_items.tax_rate_id` + `tax_rate_snapshot`, and on each first-period charge.

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

## Insurance

- `Insurance` (plan) follows the **same pattern as UnitClass pricing**: an `InsuranceRate` junction at the **Site (Center) level** carries the `price_id`.
- Insurance is **facility-scoped**, not per-building (there is no building layer).
- Optional `tax_rate_code` default for IPT / similar.
- Sold on contracts as a `ContractItem` line (polymorphic — see `04-crm-pipeline.md` / `05-billing-ledger.md`).

## Discount

- Referenced throughout (notably on `OfferOption`) and on `ContractItem` discount columns.
- Core semantic: a Discount expresses a **reduction relative to a price**, never a standalone amount.
- Discounts API + panel UI are actively in progress.
- **v1 contract billing does not yet snapshot a discount JSON** onto items — discount columns exist; full model is still formalising (`10-open-decisions.md`).

## Tables

| Table | Purpose |
|---|---|
| `prices` | Immutable price rows |
| `unit_class_rates` | Site × UnitClass → price |
| `insurances` | Insurance plans (+ optional `tax_rate_code`) |
| `insurance_rates` | Site × Insurance → price |
| `tax_rates` | Immutable exclusive tax versions by `code` |
| `discounts` | Reductions (in progress) |
| `unit_classes` | Also carries optional `tax_rate_code` |
