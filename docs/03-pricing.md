# Pricing Domain

## Price — the shared reusable model

Any entity that needs pricing references a `price_id`. Current consumers: `UnitClassRate`, `InsuranceRate` (a.k.a. InsurancePlanRate). Future: anything sellable.

**Fields:**

| Field | Type / rule |
|---|---|
| `amount` | `NUMERIC(10,2)` — **floats are never used for money** |
| `currency` | ISO code |
| `billing_period` | e.g. monthly |
| `effective_from` | start of validity |
| `effective_to` | `NULL` when current |
| `created_by` | audit reference |

## Immutability rule (hard invariant)

**Never `UPDATE` a price amount.** A rate change is always:

1. Insert a new `prices` row.
2. Insert a new junction row (`unit_class_rates` / `insurance_rates`) pointing at it.
3. Close the old price by setting `effective_to`.

History is preserved by construction. This applies to every priced entity.

## Insurance

- `Insurance` (plan) follows the **same pattern as UnitClass pricing**: an `InsuranceRate` junction at the **Site (Center) level** carries the `price_id`.
- Insurance is **facility-scoped**, not per-building (there is no building layer).
- Sold on contracts as a `ContractItem` line (polymorphic — see `05-billing-ledger.md`).

## Discount

- Referenced throughout (notably on `OfferOption`) but **not yet fully formalised** — it is the next data model to define.
- Core semantic: a Discount expresses a **reduction relative to a price**, never a standalone amount.
- Discounts API + panel UI are actively in progress.

## Tables

| Table | Purpose |
|---|---|
| `prices` | Immutable price rows |
| `unit_class_rates` | Site × UnitClass → price |
| `insurances` | Insurance plans |
| `insurance_rates` | Site × Insurance → price |
| `discounts` | Reductions (in progress) |
