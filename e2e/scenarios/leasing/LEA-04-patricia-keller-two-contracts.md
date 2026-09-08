---
id: LEA-04
title: Patricia Keller has two contracts and one vacated deposit deduction
domain: leasing
route: /leasing/contacts
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [kellys, MAD-01]
invariants: []
---

## Preconditions

Compact demo world. Patricia Keller (handle `kellys`, company **Los Keller**, MAD-01) has two contracts: one still active, one vacated with a cleaning-fee deposit deduction.

## Steps

1. Log in as `ops`. Go to `/leasing/contacts`. Heading **Contacts**.
2. Search for **Patricia Keller**. Open the row.
3. Confirm the company field or subtitle shows **Los Keller**.
4. Open the contracts tab or follow through to `/leasing/contracts` filtered to Patricia Keller.
5. Confirm two contracts exist: one active (or current) and one vacated / ended.
6. Open the vacated contract. Find the deposit settlement with a **Cleaning fee** (or €50) deduction.

## Expected

- Contact display name is **Patricia Keller**; company is **Los Keller**.
- Two contracts are visible.
- The vacated contract shows a deposit deduction for cleaning.
- Do not record a new settlement or vacate the active unit.

## Known traps

- The story is marketed as "Los Keller"; the contact row is **Patricia Keller**. Class name `TheKellys` never appears.

## On failure

Screenshot the contact header (company) and both contract rows / the settlement. Do not invent a second contact. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
