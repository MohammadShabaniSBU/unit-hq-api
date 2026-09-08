---
id: LEA-08
title: The MAD-01 unit map loads with a legend and Marcos Vega's unit
domain: leasing
route: /leasing/unit-map
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [marcus, MAD-01]
invariants: []
---

## Preconditions

Compact demo world. Marcos Vega (MAD-01) has an active contract (transferred SS4 → SS6 in the full world; on compact he is still an occupied MAD-01 unit).

## Steps

1. Log in as `ops`. Go to `/leasing/unit-map`. Heading **Unit map**.
2. Select **Madrid Centro** / MAD-01 if a site picker is present.
3. Wait for the map (or the list/map chrome) to load. Confirm an occupied vs vacant legend or unit-state swatches.
4. Find Marcos Vega's unit (hover, search, or click occupied shapes). Do **not** start an offer from the map.

## Expected

- The MAD-01 map (or map chrome) loads without a fatal error.
- Occupied / vacant (or equivalent unit-state) legend is visible.
- Marcos Vega is associated with a unit on this site.
- Creating an offer or reservation from the map is out of scope (`mutates: false`).

## Known traps

- Type **Marcos Vega**, not `MarcusWebb`.
- Unit-class **labels** are Spanish (`Trastero 10 m²`), not `SS6`.

## On failure

Screenshot the map with the site and legend visible. Do not create an offer. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
