---
id: LEA-01
title: Gracia Lin's deal is negotiating and her offer is viewed, not accepted
domain: leasing
route: /leasing/deals
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [grace, MAD-01]
invariants: []
---

## Preconditions

Compact demo world. Gracia Lin (handle `grace`, MAD-01) has a deal in **negotiating** and an offer in **viewed** that was not accepted (`fixtures.md`).

## Steps

1. Log in as `ops`. Go to `/leasing/deals`. Heading **Deals**.
2. Search for **Gracia Lin**. Open the row.
3. Confirm the deal stage is **Negotiating**.
4. Go to `/leasing/offers`. Heading **Offers**.
5. Search for **Gracia Lin**. Open the row.
6. Confirm the offer status is **Viewed**. Confirm it is not accepted (no accepted timestamp / accepted badge).

## Expected

- The deal is present and **Negotiating**.
- The offer is present and **Viewed**, not accepted.
- Do not send, accept, or edit the offer.

## Known traps

- Type **Gracia Lin**, not `GraceLin`.
- Board vs list toggle is fine as long as the search is applied.

## On failure

Screenshot the deals list (or deal detail) and the offers list (or offer detail). Do not accept the offer. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
