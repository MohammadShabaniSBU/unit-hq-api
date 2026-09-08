---
id: FAC-05
title: Access control loads with a site filter
domain: facility
route: /facility/access-control
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [MAD-01]
invariants: []
---

## Preconditions

Compact demo world. Access events may be sparse on a 10-day clock. Denied-door events are **not** required.

## Steps

1. Log in as `ops`. Go to `/facility/access-control`. Heading **Access control** (or **Access events**).
2. Confirm a site filter is present. Select **Madrid Centro** / MAD-01 if the default is All sites.
3. Confirm the page renders (table, empty state, or event rows). Do not grant, revoke, or sync access.

## Expected

- The page and site filter load without a fatal error.
- An empty events table is a pass. A missing heading or a crash is a fail.
- Overlock / denied-door rows are **not** required on compact.

## Known traps

- Do not fail because Lucía has no denied door (S-03 / compact clock).
- Live Sensorberg sync is out of scope.

## On failure

Screenshot the access-control heading and site filter. Do not grant access. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
