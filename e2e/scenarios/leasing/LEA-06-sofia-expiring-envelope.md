---
id: LEA-06
title: Ops finds Sofía Marín's MAD-02 expiring envelope
domain: leasing
route: /leasing/contracts
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [sofia, MAD-02]
invariants: []
---

## Preconditions

Compact demo world. Sofía Marín (handle `sofia`, MAD-02) is awaiting contract; the envelope expires within ~2 days of seed-end. This scenario uses company-wide `ops`. The MAD-01 agent negative control stays on S-02.

## Steps

1. Log in as `ops`. Go to `/leasing/contracts`. Heading **Contracts**.
2. Search for **Sofía Marín** (accented).
3. Open the row. Confirm the site is Madrid Norte / MAD-02 (or the Norte display name).
4. Find the e-sign / envelope chip or card. Confirm it is still outstanding and expiring soon (amber / expiring copy is enough).

## Expected

- `ops` can see Sofía (company-wide).
- Envelope is not signed and shows an expiry / expiring affordance.
- Do not send, remind, or open a live Signable session.

## Known traps

- Type **Sofía Marín**. Do not use this scenario to re-test `agent-mad` site scope (S-02).
- Compact has no MAD-05; do not substitute Diego Hoyos.

## On failure

Screenshot the contracts search and the envelope chip. Do not resend. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
