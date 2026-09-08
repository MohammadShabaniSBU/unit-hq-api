---
id: BIL-02
title: Rafa Núñez's payment link is paid, not pending
domain: billing
route: /leasing/contracts
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [rafa, MAD-01]
invariants: [11]
---

## Preconditions

Compact demo world. Rafa Núñez (handle `rafa`, MAD-01) had a payment link sent in-thread and paid via the seed's synthetic Stripe path. Confirmation is rail-recorded — do not treat a client-only "paid" chip as enough if the contract still shows pending.

## Steps

1. Log in as `ops`. Go to `/leasing/contracts`. Search **Rafa Núñez**. Open the contract.
2. On overview (and **Payment links** / invoices if those tabs exist), find the payment-link row or paid payment.
3. Confirm status **Paid**, not pending / cancelled.
4. Optionally open `/billing/invoices` and confirm a Rafa row exists. Do not create a new link.

## Expected

- Rafa's contract is findable and active (or current).
- The payment link (or the payment it created) is **Paid**.
- Do not click Create payment link, copy a live checkout URL as a write, or send the link again.

## Known traps

- Type **Rafa Núñez**. Class name `RafaNunez` never appears.
- `/billing/payments` is a header-only stub — do not use it.

## On failure

Screenshot the contract overview (or payment-links list) with the paid status. Do not create a link. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
