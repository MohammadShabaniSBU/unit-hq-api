---
id: FAC-04
title: Insurance plans lists at least one plan
domain: facility
route: /facility/insurance-plans
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: []
invariants: []
---

## Preconditions

Compact demo world. Stage seeded at least one insurance plan.

## Steps

1. Log in as `ops`. Go to `/facility/insurance-plans`. Heading **Insurance plans**.
2. Stay on the list view.
3. Confirm at least one plan row is present.

## Expected

- The list loads with one or more plans.
- An empty list after a successful compact seed is a fail.
- Do not create, archive, or edit a plan.

## Known traps

- Do not require a specific plan name that is not in `fixtures.md`. Any seeded plan row is enough.

## On failure

Screenshot the insurance-plans list. Do not create a plan. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
