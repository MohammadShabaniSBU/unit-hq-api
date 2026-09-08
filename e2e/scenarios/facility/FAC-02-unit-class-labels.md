---
id: FAC-02
title: Unit classes list the Spanish SS1–SS8 labels
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

Compact demo world. Stage seeded unit classes `SS1`–`SS8` (and AL*) with the labels in `fixtures.md`.

## Steps

1. Log in as `ops`. Go to `/facility/unit-classes`. Heading **Unit class**.
2. Stay on the list view. Page if needed.
3. Confirm labels **Trastero 5 m²** through **Trastero 12 m²** (SS1–SS8) appear.

## Expected

- The list loads.
- SS1–SS8 Spanish labels from `fixtures.md` are present. AL labels may also appear; they are not required.
- Do not create or edit a class.

## Known traps

- Heading is **Unit class**, not "Unit classes".
- Searching by code `SS4` may fail; search **Trastero 8 m²**.

## On failure

Screenshot the unit-class list. Do not create a class. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
