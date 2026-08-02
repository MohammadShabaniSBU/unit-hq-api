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
- Sites belong to a **legal entity**, which holds payment credentials and fiscal regime (see `roadmap/architecture-payments-and-fiscal.md`). Integration surfaces that remain per-site: floor maps and sender identities — see later phases / `05` / `06`.

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
- **Availability is always derived** — there is **no `is_available` column**. A unit is available on date D when it has no covering `unit_occupancies` row and no unreleased blocking `unit_holds` row (half-open ranges; see invariant 36). Resolve “today” through `SiteClock` per site. Never store availability as a flag.

## UnitClassRate — pricing junction

- Static junction between `UnitClass` and `Site` — created **once** per pairing; never versioned.
- Catalogue prices (`prices.scope = catalogue`) morph-own the junction (`priceable` → `unit_class_rate`) and carry `effective_from` / `effective_to`.
- A rate change means: **close the current catalogue price via `effective_to`, insert a successor price owned by the same junction**. Never update a price amount in place; never insert a second junction row. (See `03-pricing.md` and `roadmap/sprint-02-pricing-model/architecture-pricing.md`.)

## Access points

Physical locks/gates/zones discovered from an access provider and mapped into the
facility hierarchy (S15).

- **`access_points`** bind a provider point (`provider_point_id` + label) to a
  `site_id` and optional `unit_id`.
- Point types: `unit_door` (requires `unit_id`), `gate`, `zone` (site-level —
  `unit_id` null).
- **One live door per unit** (partial unique on `unit_id` where not archived).
  Multi-door units are deferred — see `10-open-decisions.md`.
- Mapping is archive-only (`archived_at`); archive releases the unit slot so a
  replacement door can be assigned.
- Discovery cache lives on `access_provider_accounts.discovered_points`; the
  mapping UI surfaces unassigned, assigned, and assigned-but-vanished (removed at
  the provider) rows. Bulk label→`unit_number` suggestions exist for large sites.
- Desired grants / sync / suspensions are access-domain concerns (S15 roadmap);
  facility pages expose mapped state and the events log only.

## Related tables

| Table | Purpose |
|---|---|
| `sites` | Facilities |
| `unit_classes` | Sellable products |
| `units` | Physical boxes |
| `site_maps` | Visual facility maps (SVG; id ↔ `unit_number`) |
| `unit_class_rates` | Site × class → price junction |
| `access_points` | Mapped provider locks/gates/zones (S15) |
| `payment_provider_accounts` | Per-entity Stripe (and future debit) credentials + webhook routing — see `05-billing-ledger.md` |
| `site_sender_identities` | Per-site comms from-address / from-number |
| `communication_accounts` | Provider API credentials (company- or site-scoped) |
