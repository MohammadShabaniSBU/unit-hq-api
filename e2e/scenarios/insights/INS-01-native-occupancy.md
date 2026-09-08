---
id: INS-01
title: Insights nav lists native reports and occupancy or dashboard renders
domain: insights
route: /insights
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: []
invariants: []
---

## Preconditions

Compact demo world. Native reports are seeded (`dashboard`, `occupancy`, `rent-roll`, …). No Metabase / analytics account is required.

## Steps

1. Log in as `ops`. Open the **Insights** nav section (not Settings → Insights).
2. Confirm native labels such as **Occupancy**, **Dashboard**, or **Rent roll** appear. Do not open an item labelled as embedded / Metabase.
3. Go to `/insights` (may redirect to `/insights/{dashboard-key}`).
4. Open **Occupancy** (`/insights/occupancy` or the nav item). If occupancy is missing, open **Dashboard**.
5. Confirm the native report shell renders (heading **Occupancy** or **Insights** / dashboard). A missing embed iframe is not a fail.

## Expected

- Native report names are in the nav.
- Occupancy or dashboard paints without a 500. Empty charts / no-data copy is a pass.
- Do not open Settings → Analytics connections or require a live embed.

## Known traps

- `/insights` redirects to the dashboard key when one exists. That is fine.
- Financial reports (`rent-roll`) are INS-02.

## On failure

Screenshot the Insights nav and the native report body. Do not connect Metabase. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
