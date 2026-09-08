---
id: SET-03
title: Settings sites lists only MAD-01 and MAD-02 after compact seed
domain: settings
route: /settings/facility/sites
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [MAD-01, MAD-02]
invariants: []
---

## Preconditions

Compact demo world (`demo:seed --fresh --compact`). Only Madrid Centro (MAD-01) and Madrid Norte (MAD-02) exist. MAD-03…05 must be absent.

## Steps

1. Log in as `ops`. Go to `/settings/facility/sites` (or `/facility/sites` if that is the sites catalogue the sidebar uses — prefer the settings facility sites path first). Heading **Sites**.
2. Set status to **Active** (or **All** if Active hides a site).
3. Confirm rows for **Madrid Centro** / **MAD-01** and **Madrid Norte** / **MAD-02**.
4. Confirm **Madrid Sur**, **Madrid Este**, **Madrid Oeste** (MAD-03…05) are not listed.

## Expected

- Exactly the two compact sites are present (codes MAD-01 and MAD-02).
- A third Madrid site is a fail (wrong seed: full presenter world).
- Do not create or archive a site.

## Known traps

- Display names are **Madrid Centro** / **Madrid Norte**, not the handles `madrid` / `norte`.
- If the settings path 404s, try `/facility/sites` and record which route worked. A missing sites list entirely is a fail.

## On failure

Screenshot the sites table with codes/names visible. Do not create a site. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
