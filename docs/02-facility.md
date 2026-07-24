# Facility Domain

## Hierarchy

```
Site (Center) → UnitClass → Unit
```

The **Building layer has been removed** — the hierarchy is intentionally flat: Site → Unit, with UnitClass as the commercial grouping.

## Site

The physical storage facility (also called "Center").

- Settings (currency, billing cadence / anchor / proration / deposit, timezone, late-fee rules, tax rates) are **singleton / catalogue rows** in the single database — mono-tenant, so no company scoping is needed.
- Site maps (`site_maps`) support the visual unit map in the panel.

## UnitClass — the sellable product

The **commercial product definition**, not the physical box.

- Fields: `slug`, `label`, `tier` (for grouping), nominal dimensions (W×D×H), amenities, a pointer to its **current price**, and optional `tax_rate_code` (default exclusive tax for new contract lines).
- **Type and size are not separate dimensions** — the class *is* the product identity.
- Billing and public listings always use **class dimensions**, never per-unit dimensions.

## Unit — the physical instance

- References its `UnitClass` and its `Site`.
- Optional `actual_*` dimension overrides exist **only** when a surveyed physical unit differs from its class nominal dimensions.
- **Availability is always derived** — there is **no `is_available` column**. A unit is available when it has no active contract and no non-expired reservation. Never store availability as a flag.

## UnitClassRate — pricing junction

- Junction between `UnitClass` and `Site`, holding a `price_id`.
- Rates can differ per **site × class** combination.
- A rate change means: **insert a new `Price` row + new `unit_class_rates` row, close the old one via `effective_to`**. Never update a price in place. (See `03-pricing.md`.)

## Related tables

| Table | Purpose |
|---|---|
| `sites` | Facilities |
| `unit_classes` | Sellable products |
| `units` | Physical boxes |
| `site_maps` | Visual facility maps |
| `unit_class_rates` | Site × class → price junction |
