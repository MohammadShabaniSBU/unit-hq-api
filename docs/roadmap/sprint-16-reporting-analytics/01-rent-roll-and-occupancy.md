# S16-01 — Rent roll & occupancy

## Context

The two reports every storage operator opens first, and the ones your fact tables
were built for: the **rent roll** (who's in what, paying what, owing what — as of
any date) and **occupancy** in its three definitions, with history.

## Scope

**In:** rent roll report (as-of + current), the three-occupancy report with
per-class×site breakdown and a monthly trend series, cross-surface consistency
fixtures, the as-of edge fixtures. **Out:** revenue-management analytics (rate vs
occupancy curves — later phase, noted), per-unit profitability.

## Rent roll

Row per open occupancy as of the date: unit (number, class, site, m²), tenant
(→ contact), contract (→, status, move-in, tenure), monthly rate (current unit-item
price via `itemsOn(as_of)` — plus insurance shown separately), deposit held,
balance owed / overdue (computed at as-of? **No** — balances are *current-state*
figures; an as-of rent roll shows as-of tenancy + rates but today's balances, with
the column header saying so — reconstructing historical balances means replaying
allocations and is deferred with a note; honesty over false precision), autopay
state, delinquency day-count if open, overlock/suspension glyphs.

Footer aggregates: units, m², Σ monthly rent by currency, Σ deposits (the liability
figure your accountant asks for — `deposit_settlements`-adjusted for ended
contracts), Σ overdue. Sortable, the full filter contract, CSV.

**As-of edges (the fixtures):** a date mid-transfer shows the destination unit only;
mid-notice shows the tenancy with its end-date; the day *of* a vacate shows vacated
(exclusive end); a re-let unit on the gap day shows empty; `awaiting_signature`
appears nowhere (the S14 audit posture, sixth system).

## Occupancy

One page, three headline figures (unit / area / economic, definitions linked),
as-of-date control, then:

- **Breakdown table**: per site × class — rentable, occupied, the three rates;
  economic's numerator/denominator shown on hover (the show-the-formula rule).
- **Trend**: monthly series over the filter range, computed by running the same
  point-in-time query at each month-end (bounded: one query per month via a
  generate_series-style approach or a PHP loop over ≤24 points — measure, don't
  guess; the base-case assertion covers the whole series call).
- **Consistency fixtures** (the trust countermeasure): today's unit-occupancy figure
  equals the Unit Class matrix totals (S01-03's page) and the units-list state
  counts (S01-04's tabs) on the same seed — three surfaces, one number, asserted.

## Acceptance criteria

- [ ] Rent roll matches a hand-computed seed fixture row-for-row and in every footer
      aggregate; the balance-column honesty header present.
- [ ] All five as-of edge fixtures green.
- [ ] Three occupancy figures correct on constructed seeds (incl. a maintenance-held
      unit shrinking the denominator, and a discounted tenant dragging economic
      below unit).
- [ ] The three-surface consistency fixtures green.
- [ ] Trend series bounded and correct at two spot-checked months.
- [ ] CSV + print + filters per the 00 contract.

## Tests required

| Test | Asserts |
|---|---|
| `RentRollTest::seed_fixture_and_footers` | Row-for-row truth |
| `RentRollTest::as_of_edges` | Transfer/notice/vacate-day/gap/awaiting |
| `OccupancyTest::three_definitions_constructed_seeds` | Incl. denominator + economic drag |
| `OccupancyTest::cross_surface_consistency` | Matrix, tabs, report agree |
| `OccupancyTest::trend_bounded_spot_checks` | The series |
