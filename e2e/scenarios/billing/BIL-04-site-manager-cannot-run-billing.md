---
id: BIL-04
title: A MAD-01 site manager cannot execute a billing run
domain: billing
route: /billing/runs
persona: sm-mad-01
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [MAD-01]
invariants: []
---

## Preconditions

Compact demo world. `sm-mad-01` is Site Manager MAD-01. The role does not include `billing.run.execute`. Complements S-04 (`ops` can run).

## Steps

1. Log in as `sm-mad-01` (`sm-mad-01@example.com`).
2. Navigate to `/billing/runs` (paste the path; do not rely on the sidebar).
3. Observe whether the list loads, errors, or redirects.
4. Confirm **Run billing now** is absent. Do not try to execute a run via the API.

## Expected

- **Run billing now** is not shown.
- A list, a load error, or a redirect away from execution is acceptable. Staying on a page that offers **Run billing now** is a fail.
- Do not log in as `ops` to "complete" the click.

## Known traps

- Direct URL is the assertion. A missing nav item alone is not enough.
- `accountant` and `ops` **can** execute — do not use them here.

## On failure

Screenshot the URL bar and the billing-runs surface (or the redirect target). Do not run billing. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
