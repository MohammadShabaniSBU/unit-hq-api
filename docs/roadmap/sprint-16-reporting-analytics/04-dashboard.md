# S16-04 — Dashboard & polish

## Context

The daily glance: Insights' landing page answering the operator's morning questions
— how full, how much owed, what needs me today — from the same query classes the
full reports use, at card zoom. Plus the sprint's close-out: exports everywhere,
docs, es.

## Scope

**In:** the dashboard page (KPI cards + two trends + attention row), drill-through
links, a lightweight chart component (one library, small — or CSS bars; decide by
what `bun` already carries, no heavy charting dependency for six bars), close-out
sweep. **Out:** configurable dashboards, per-employee layouts, scheduled email
reports (all recorded — the last is a natural playbook action later).

## Panel surface

**KPI row** (site-filtered by the global selector, as-of today): unit occupancy %
(with economic beneath, smaller — the gap visible daily is the pricing conscience),
Σ monthly rent in place, overdue total + contract count, open delinquency cases,
this-month move-ins/outs net. Each card: the figure, a small delta vs last month
(computed from the same query at the two dates), and a click-through to its full
report with filters pre-set.

**Trends**: occupancy (unit + economic lines, 12 months — 01's series) and monthly
collected-vs-charged bars (02's rates). Honest axes (zero-based for the bars;
occupancy may zoom with the axis labelled — the two charts state their scales).

**Attention row** — the existing chips, gathered: failed autopay, drift
denied-but-granted, signed-after-cancellation, triage count, expiring signatures,
pending deposit payouts. Each links its owning surface. This row is arguably the
dashboard's real job; it renders first on mobile widths.

**Close-out sweep:** CSV + print verified on every 01–03 report against the 00
contract (a checklist, not new code); `docs/report-definitions.md` linked from every
footer; `00-overview.md` + `01-stack.md` gain the Insights reality;
`10-open-decisions.md` collects the sprint's recorded deferrals in one pass; es
review of the full sprint's vocabulary (*Tasa de ocupación*, *Antigüedad de deuda*,
*Cierre diario*, *Embudo de conversión* — accountant-facing words, operator-reviewed).

## Acceptance criteria

- [ ] Every card equals its full report's figure on the same filters (the
      consistency discipline, dashboard edition — fixture per card).
- [ ] Deltas correct across a month boundary fixture; drill-throughs land with
      filters applied.
- [ ] Trends render from the report series; axes labelled per the honesty rule.
- [ ] Attention row: every chip live, counted, linked.
- [ ] The sweep: all checklist items ticked in the PR; docs + es complete.

## Tests required

| Test | Asserts |
|---|---|
| `DashboardTest::cards_equal_reports` | Per-card fixtures |
| `DashboardTest::deltas_month_boundary` | The comparison math |
| `DashboardTest::attention_row_live` | Chips counted + linked |
| Panel manual script | Drill-throughs, trends, mobile attention-first, print |
