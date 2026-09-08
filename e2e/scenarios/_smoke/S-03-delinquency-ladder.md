---
id: S-03
title: Lucía Ferrer's open case shows a started ladder and matching balances
domain: _smoke
route: /billing/delinquency
persona: ops
mutates: false
priority: smoke
requires_db: any
depends_on: []
anchors: [lucia, MAD-01]
invariants: []
---

## Preconditions

Compact demo world (`demo:seed --fresh --compact`). Lucía Ferrer (MAD-01, handle `lucia`) has an **open** delinquency. A 10-day clock cannot reach overlock (policy day 12) or the 15–30 ageing bucket. Expect the ladder to have **started** (late fee and/or notice), typically in **8–14 days**.

A full presenter seed still builds 15–30 / overlocked / denied door — do not require those here.

## Steps

1. Log in as `ops`. Go to `/billing/delinquency`. The heading is **Delinquency**.
2. Set the day filter to **8–14 days**. If the list is empty, try **All** / **1–7 days** before failing.
3. Find the row whose contact name is **Lucía Ferrer** (the contract column reads like `UNIT · Lucía Ferrer`).
4. Note the overdue amount on that row.
5. Open the row. The panel goes to `/leasing/contracts/:id?tab=delinquency`.
6. Read the case timeline top to bottom.
7. Open the contract **Overview** tab and read the balance owed.

## Expected

- Lucía's row is present and the case is **open** and still owing. A missing row or a cured/empty timeline is a fail.
- Timeline contains at least a late fee and/or a notice (policy ~day 5 / ~day 8). Access suspension may also appear.
- Overlock and a denied door event are **not** required on compact.
- The overdue figure on the delinquency board equals the balance owed on the contract overview (derived at query time — a mismatch is a product bug, not a stale cache).

## Known traps

- Type **Lucía Ferrer**, not Lucia Ferrer and never `LuciaFerrer`.
- Ageing buckets in the UI are labelled **1–7 days**, **8–14 days**, **15–30 days**, **30+**. Compact Lucía belongs in 8–14, not 15–30.
- Do not fail the scenario because overlock / denied-door copy is absent.

## On failure

Screenshot the filtered board (with the amount visible) and the delinquency timeline. Record both balance figures. Do not record a payment or close the case. Do not fix the bug — report it in `e2e/runs/<date>/bugs.md`.
