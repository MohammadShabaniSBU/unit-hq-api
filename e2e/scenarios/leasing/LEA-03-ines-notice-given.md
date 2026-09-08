---
id: LEA-03
title: Inés Valdés's contract shows Notice given and a scheduled move-out
domain: leasing
route: /leasing/contracts
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [ingrid, MAD-01]
invariants: []
---

## Preconditions

Compact demo world. Inés Valdés (handle `ingrid`, MAD-01) has **Notice given**. Scheduled move-out is 5–14 days after seed-end. Do not use `/leasing/move-outs` (header-only stub).

## Steps

1. Log in as `ops`. Go to `/leasing/contracts`. Heading **Contracts**.
2. Search for **Inés Valdés** (accented).
3. Confirm the row status is **Notice given**.
4. Open the contract. Read the overview (and Notice tab if present) for the scheduled move-out date.

## Expected

- Status is **Notice given**.
- A scheduled move-out date is visible and falls 5–14 days after `2025-06-10`.
- Do not withdraw notice or vacate.

## Known traps

- Type **Inés Valdés**, not Ines Valdes and never `IngridWeiss`.
- `/leasing/move-outs` is a stub. A missing heading there is not this scenario.

## On failure

Screenshot the contracts list (status visible) and the contract overview / notice surface. Do not withdraw notice. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
