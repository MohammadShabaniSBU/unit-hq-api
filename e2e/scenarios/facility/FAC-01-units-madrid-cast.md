---
id: FAC-01
title: The MAD-01 units list shows a named cast unit with a Spanish class label
domain: facility
route: /facility/units
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [marcus, lucia, MAD-01]
invariants: []
---

## Preconditions

Compact demo world. Marcos Vega and Lucía Ferrer occupy MAD-01 units. Class codes (`SS3`, `SS6`) must not appear as the only class text — the UI shows Spanish labels (`Trastero 7 m²`, `Trastero 10 m²`).

## Steps

1. Log in as `ops`. Go to `/facility/units`. Heading **Units**.
2. Filter or select site **Madrid Centro** / MAD-01 if a site control is present. Stay on **List** (do not require the map toggle).
3. Search for **Marcos Vega** or scan occupied rows for his unit. If Marcos is missing, search **Lucía Ferrer**.
4. Read the class column on that row.

## Expected

- At least one named cast unit (Marcos or Lucía) is listed for MAD-01.
- The class column uses a Spanish label from `fixtures.md` (e.g. **Trastero 10 m²**), not a bare `SS6` / `SS3` code as the only text.
- Do not create, archive, or edit a unit.

## Known traps

- Type **Marcos Vega** / **Lucía Ferrer**. Codes `SS1`–`SS8` are not what the occupant sees.
- Compact has no MAD-03…05.

## On failure

Screenshot the units list with the site filter and class column visible. Do not create a unit. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
