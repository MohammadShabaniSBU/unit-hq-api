# Sprint 16 — Reporting & Analytics

## Goal

The Insights section stops being an empty nav group: **rent roll**, the **three
occupancy measures**, **delinquency ageing**, **collections** (including
promise-kept), **daily close & cash reconciliation**, **movement** (move-ins/outs/
transfers), and the **lead funnel** — every one computed live from the fact tables
that were designed for exactly this, filterable by site and date, exportable to CSV,
grouped-never-summed across currencies.

## The harvest principle

Nothing in this sprint stores a report number. Every figure derives at read time from
facts already append-only: `unit_occupancies` answers "occupancy on any date D"
(S01's founding promise), the ledger answers every money question (invariant 5's
payoff), `deposit_settlements`, `delinquency_steps`, `call_wrapups.payment_promised`,
`autopay_attempts`, `contract_transfers`, `access_events` — the data has been
accumulating on purpose. If a task proposes a rollup table, it is proposing to cache
what a bounded query answers; the answer is an index, not a table. (The one sanctioned
future exception — nightly snapshots *if* a report proves slow at real scale — is
pre-recorded in `10-open-decisions.md` by task 00, so the escape hatch is designed,
not improvised.)

## Definitions are the deliverable

Three numbers all called "occupancy", ageing buckets, "economic" anything — every
term gets pinned in `docs/report-definitions.md` (task 00) **before** queries are
written, and every report page links its definitions. An operator who catches two
pages disagreeing about "occupied" stops trusting all of them; the definitions doc +
cross-report consistency fixtures are the countermeasure.

## Exit criteria

- [x] Rent roll for any as-of date matches hand-computed truth on the seed; the three
      occupancy figures render with their definitions one click away and agree with
      the Unit Class matrix and the unit list counts (cross-surface fixtures).
- [x] Ageing buckets sum to total overdue to the cent; collections shows
      promise-kept and autopay-recovery rates; daily close reconciles a seeded cash
      day by method and employee.
- [ ] Movement and funnel reports over a seeded quarter tell a coherent story
      (transfers are neither churn nor acquisition — the S02 promise, finally
      visible).
- [ ] Every report: site + date filters, currency grouping, CSV export, print CSS,
      bounded queries asserted at seed scale.
- [x] The Insights dashboard renders the daily-glance KPIs from the same query
      classes the full reports use — one computation, two zoom levels.

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Foundations: definitions, query pattern, export](./00-foundations.md) | 1 day |
| 01 | [Rent roll & occupancy](./01-rent-roll-and-occupancy.md) | 1.5 days |
| 02 | [Money: ageing, collections, daily close](./02-money-reports.md) | 1.5 days |
| 03 | [Movement & funnel](./03-movement-and-funnel.md) | 1 day |
| 04 | [Dashboard & polish](./04-dashboard.md) | 1 day |

## Risks

**Point-in-time correctness is subtler than it looks.** "Occupancy on 1 March" must
use the fact-table windows (`started_on <= D < ended_on`, exclusive ends — the house
boundary convention, final exam), *site-local* D, and contracts by their status *as
of the ledger*, not today. The as-of fixtures test dates spanning a transfer, a
vacate, and a re-let of the same unit.

**Economic occupancy divides two constructed numbers.** Numerator and denominator
definitions (task 00) are where operators will argue; ship the standard industry
definitions, show the formula on the page, and make the denominator's rate source
explicit (current catalogue price per class — the S02-00a ownership model makes this
one join).

**CSV is an interface with Excel-es.** Spanish Excel expects `;` separators and
`,` decimals; export respects the operator's locale or every money column imports as
text. Small, maddening, tested.
