---
id: BIL-03
title: Nadia Rahal's contract shows 20% off and a rate change
domain: billing
route: /leasing/contracts
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [nadia, MAD-01]
invariants: []
---

## Preconditions

Compact demo world. Nadia Rahal (handle `nadia`, MAD-01) has the **20% off** tracking discount and at least one applied and/or scheduled rate change.

## Steps

1. Log in as `ops`. Go to `/leasing/contracts`. Search **Nadia Rahal**. Open the contract.
2. Find the discounts block. Confirm **20% off** (or 20% / percent) is applied and still tracking rate changes if that chip is shown.
3. Find rate-change history or schedule (overview, items, or a rates / changes section).
4. Confirm at least one applied change **or** one scheduled change is visible. Do not add, remove, or schedule a new change.

## Expected

- Discount **20% off** is visible on the contract.
- A rate-change history and/or schedule is visible.
- Do not remove the discount or post a new price.

## Known traps

- Type **Nadia Rahal**, not `NadiaRahal`.
- Prices are never updated in place — history rows are the assertion.

## On failure

Screenshot the discount block and the rate-change history/schedule. Do not remove the discount. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
