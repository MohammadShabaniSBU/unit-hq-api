---
id: LEA-05
title: Javier Peña's awaiting contract shows a declined envelope
domain: leasing
route: /leasing/contracts
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [jean_luc, MAD-01]
invariants: []
---

## Preconditions

Compact demo world. Javier Peña (handle `jean_luc`, MAD-01) is awaiting signature with a **declined** envelope.

## Steps

1. Log in as `ops`. Go to `/leasing/contracts`. Heading **Contracts**.
2. Search for **Javier Peña** (accented). Open the row (or the declined-attention filter if the list uses one).
3. Confirm the contract is awaiting signature, not active.
4. Find the e-sign / envelope chip or card. Confirm it is **Declined** (a reason may be present).

## Expected

- Javier's contract is findable and not fully signed / active.
- Envelope status is **Declined**.
- Do not resend the envelope or open a live Signable session.

## Known traps

- Type **Javier Peña**, not Javier Pena and never `JeanLucPerrin`.
- Live e-sign send is out of scope.

## On failure

Screenshot the contract list/detail with the declined chip visible. Do not resend. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
