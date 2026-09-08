---
id: SET-02
title: The tax-rates catalogue loads
domain: settings
route: /settings/tax-rates
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: []
invariants: []
---

## Preconditions

Compact demo world. Stage seeded at least one tax rate. `ops` can open settings that do not need `rbac.manage`.

## Steps

1. Log in as `ops`. Go to `/settings/tax-rates`. Heading **Tax rates**.
2. Confirm the catalogue table (or list) loads with at least one rate row (a default / ongoing rate is enough).
3. Do not add, edit, or set default. Do not open history unless the list itself is empty and history is the only way to see a current rate — prefer the live list.

## Expected

- The page loads and shows at least one tax rate.
- An empty catalogue after compact seed is a fail.
- Do not write a new rate (tax rates are closed + inserted, never updated in place).

## Known traps

- If `ops` is denied, that is a product/permission fail — do not switch to `accountant` to force a pass unless the page explicitly redirects and you record the redirect. Prefer retrying as `accountant` only if `ops` is documented as blocked; `personas.md` says ops can see settings that do not need RBAC.

## On failure

Screenshot the tax-rates list. Do not add a rate. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
