---
id: LEA-02
title: Omar Haddad's contract is Pending with move-in after seed-end
domain: leasing
route: /leasing/contracts
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [omar, MAD-01]
invariants: []
---

## Preconditions

Compact demo world. Omar Haddad (handle `omar`, MAD-01) has a **Pending** contract, signed, with move-in 10 days after seed-end (`2025-06-20`).

## Steps

1. Log in as `ops`. Go to `/leasing/contracts`. Heading **Contracts**.
2. Filter status to **Pending** (or search **Omar Haddad** if the filter is not obvious).
3. Find the row for **Omar Haddad**. Confirm status **Pending**.
4. Open the row. Confirm the contract is signed and the move-in date is after seed-end (`2025-06-10`).

## Expected

- Omar appears under **Pending**.
- The detail shows a signed contract whose move-in is after compact seed-end.
- Do not activate, cancel, or edit the contract.

## Known traps

- Type **Omar Haddad**. Class name `OmarHaddad` never appears.
- Compact seed-end is **2025-06-10**. Move-in should be **2025-06-20**.

## On failure

Screenshot the filtered contracts list and the contract overview. Do not change status. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
