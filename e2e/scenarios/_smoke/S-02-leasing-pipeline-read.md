---
id: S-02
title: A MAD-01 leasing agent sees Lucía Ferrer in the pipeline and cannot see Sofía Marín
domain: _smoke
route: /leasing/contacts
persona: agent-mad
mutates: false
priority: smoke
requires_db: any
depends_on: []
anchors: [lucia, sofia, marcus, MAD-01, MAD-02]
invariants: []
---

## Preconditions

Demo world seeded. Lucía Ferrer and Marcos Vega are MAD-01 cast. Sofía Marín is MAD-02 cast (see `fixtures.md`).

## Steps

1. Log in as `agent-mad` (`agent-mad@example.com`). Land on `/leasing/contacts`.
2. Search or filter the contacts list for **Lucía Ferrer** (accented). Open the row.
3. On the contact, open the **Deals** tab, then follow through to an offer or contract if those tabs have rows.
4. Go to `/leasing/deals` and confirm a deal for Lucía Ferrer or Marcos Vega is listed.
5. Go to `/leasing/contracts` and confirm Marcos Vega (active, transferred) or Lucía Ferrer (active) is listed.
6. Return to `/leasing/contacts`. Search for **Sofía Marín** (accented).

## Expected

- Steps 2–5 find MAD-01 cast records. Lucía's contact shows deals; Marcos's contract is **Active**.
- Step 6 returns no contact named Sofía Marín. Do not treat a crowd row as a substitute.
- Site-scoped nav does not offer MAD-02 as a working site for this persona.

## Known traps

- `LuciaFerrer` / `SofiaMarin` never appear in the UI. Accents are required.
- Sofía Marín is the negative control (MAD-02). Compact has no MAD-05; do not use Diego Hoyos or a crowd name.
- Board vs list toggle: either view is fine as long as the search is applied.

## On failure

Screenshot the contacts list with the failing search visible. If Lucía is missing, the seed is wrong — stop. Do not create a contact. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
