---
id: FAC-03
title: The rates matrix shows MAD-01 prices in EUR
domain: facility
route: /facility/rates
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [MAD-01]
invariants: []
---

## Preconditions

Compact demo world. MAD-01 has catalogue prices. Currency on price rows is EUR.

## Steps

1. Log in as `ops`. Go to `/facility/rates`. Heading **Rates**.
2. Find the **Madrid Centro** / MAD-01 column (or site heading).
3. Confirm at least one class row has a non-empty EUR price (symbol `€` or code `EUR`).
4. Do not edit a cell or save a new rate.

## Expected

- The matrix loads for MAD-01.
- At least one price is visible in EUR.
- Do not write a price (prices are immutable / new-row — a save would mutate).

## Known traps

- Compact has MAD-01 and MAD-02 only. A MAD-03 column is a fail.
- Empty cells on some classes are fine if others have prices.

## On failure

Screenshot the rates matrix with the MAD-01 column visible. Do not save a rate. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
