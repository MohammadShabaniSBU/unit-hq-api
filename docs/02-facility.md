# Facility Domain

## Hierarchy

```
Site (Center) → UnitClass → Unit
```

The **Building layer has been removed** — the hierarchy is intentionally flat: Site → Unit, with UnitClass as the commercial grouping.

## Site

The physical storage facility (also called "Center").

- Fields include address lines, `city`, `state_region`, `postal_code`, `code` (unique when set), contact info, **`country_id`** (FK → `countries`; required on create/update in the API and panel — ISO 3166-1 alpha-2 is `countries.code`, never a denormalised `sites.country_code`), and **`timezone`** (IANA string, required on create — no org default preselected). The DB column for `country_id` stays nullable until S03 makes it `NOT NULL` when every site must be placeable for jurisdiction/fiscal work.
- **`timezone` is per-site and authoritative** for date-boundary interpretation (due dates, late fees, occupancy/holds “today”). Resolve civil dates through `App\Support\Time\SiteClock` — never bare `Carbon::today()` / `->toDateString()` on a timestamp. Org billing settings (currency prefill, cadence / anchor / proration / deposit, late-fee rules, tax rates) remain **singleton / catalogue rows** — mono-tenant, no company scoping. Do **not** add an org-level timezone: any future org display timezone (reports / schedule UI) is **display-only**, never authoritative for billing.
- **`sites.currency`** (D1 form prefill) is **not** present yet — lands in S01-00b with site→org prefill readers. Until then, price forms prefill from org `BillingSettings.default_currency` only. Neither is read at transaction time; `prices.currency` is authoritative.
- Sites are **archive-only** (`archived_at`) — never hard-deleted. List/options use an explicit `active()` scope; archived sites stay resolvable by id for historical contracts. Archive is refused while the site has active contracts or non-expired reservations.
- Integration surfaces (per-site): floor maps, sender identities, Stripe keys — see later phases / `05` / `06`.

## Site maps (`site_maps`)

SVG floor plans for the visual unit map. A site may have multiple floors (`floor_name` unique per site).

- **Id-matching convention:** SVG element `id` attributes match `units.unit_number` for the same `site_id`. Upload validation reports three buckets — `matched`, `orphan_shapes` (id with no unit), `uncovered_units` (unit with no shape).
- SVG is sanitized on write (scripts, event handlers, `foreignObject`, external hrefs stripped).

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
| `site_maps` | Visual facility maps (SVG; id ↔ `unit_number`) |
| `unit_class_rates` | Site × class → price junction |
| `site_stripe_settings` | Per-site Stripe keys + webhook routing |
| `site_sender_identities` | Per-site comms from-address / from-number |
| `communication_accounts` | Provider API credentials (company- or site-scoped) |
