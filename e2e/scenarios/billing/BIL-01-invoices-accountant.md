---
id: BIL-01
title: An accountant can open a cast invoice with number and amounts
domain: billing
route: /billing/invoices
persona: accountant
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [rafa, kellys]
invariants: []
---

## Preconditions

Compact demo world. `accountant` (Carmen Contable) can view invoices. Rafa Núñez or Patricia Keller should have at least one issued invoice from the seed.

## Steps

1. Log in as `accountant` (`accountant@example.com`). Go to `/billing/invoices`. Heading **Invoices**.
2. Use the kind filter (**All kinds** / Ordinary / Simplified / Rectificative) and confirm the list updates.
3. Search or scan for **Rafa Núñez**. If none, try **Patricia Keller**.
4. Open a row (name link or eye action). Confirm the detail drawer/page shows a full number and a total amount.

## Expected

- The invoices list loads for `accountant`.
- A named cast invoice opens and shows number + amount (EUR).
- Do not issue, rectify, or download-as-a-write. Viewing is enough.

## Known traps

- Type **Rafa Núñez** / **Patricia Keller**. Do not name a crowd contact.
- `readonly` is not this persona.

## On failure

Screenshot the invoices list and the open detail. Do not issue an invoice. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
