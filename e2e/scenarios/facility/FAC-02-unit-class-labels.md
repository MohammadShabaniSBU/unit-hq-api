---
id: FAC-02
title: Unit classes list the Spanish SS5–SS12 labels
domain: facility
route: /facility/unit-classes
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: []
invariants: []
---

## Preconditions

Compact demo world. Stage seeded unit classes `SS5`–`SS12` (and AL*) with the labels in `fixtures.md`. The smaller box classes (`SS1.5`, `SS2`, `SS2.5`, `SS3`) are also present.

## Steps

1. Log in as `ops`. Go to `/facility/unit-classes`. Heading **Unit class**.
2. Stay on the list view. Page if needed.
3. Confirm labels **Trastero 5 m²** through **Trastero 12 m²** (SS5–SS12) appear.

## Expected

- The list loads.
- SS5–SS12 Spanish labels from `fixtures.md` are present. AL labels and the 1.5–3 m² box classes may also appear; they are not required.
- Do not create or edit a class.

## Known traps

- Heading is **Unit class**, not "Unit classes".
- Searching by code `SS8` may fail; search **Trastero 8 m²**.

## On failure

Screenshot the unit-class list. Do not create a class. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
