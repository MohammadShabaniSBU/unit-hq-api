# S16-00 — Foundations: definitions, query pattern, export

## Context

The rails every report runs on: the definitions doc that prevents self-contradiction,
one query-class pattern with one filter contract, the CSV/print machinery, and the
Insights shell. Half documentation, half plumbing, all leverage.

## Scope

**In:** `docs/report-definitions.md`, `ReportQuery` pattern + filter contract, CSV
exporter (locale-aware), print CSS baseline, Insights section shell + nav, the
snapshot escape-hatch entry in `10-open-decisions.md`. **Out:** every actual report
(01–03), charts (04).

## The definitions doc (write first, review with the operator)

`docs/report-definitions.md` pins, minimum:

- **Unit occupancy** = occupied units ÷ rentable units. Occupied: an open
  `unit_occupancies` row covering the as-of date. Rentable: non-archived units minus
  those under a blocking non-reservation hold on that date (maintenance/damaged/
  staff/other exclude from the denominator; a unit you *can't* rent shouldn't count
  against you — state it, defend it, it's the standard).
- **Area occupancy** = occupied m² ÷ rentable m², same rules, dimensions from units.
- **Economic occupancy** = actual in-place rent ÷ gross potential rent. Numerator:
  Σ current item-version prices of unit items on active/notice contracts (the
  `itemsOn` read). Denominator: Σ current catalogue price per unit's class×site over
  rentable units. Discounted or legacy-rated tenants drag it below unit occupancy —
  that gap *is the report's point*.
- **Ageing buckets**: 1–7 / 8–14 / 15–30 / 31–60 / 60+ days past due, per *charge*
  due date (the S07 anchor vocabulary), bucketed by oldest-unpaid per contract for
  the contract view and per-charge for the totals — the two views reconcile by
  construction and a fixture proves it.
- **Collections rate** (period) = allocated-to-period-charges ÷ charged, by charge
  type. **Promise-kept** = `payment_promised` wrap-ups followed by any allocation to
  that contract within N days (default 7, shown on page). **Autopay recovery** =
  failed attempts later collected by any rail.
- **Movement**: move-in = occupancy opened (excl. `transferred_out` counterparts);
  move-out = occupancy `ended_reason = vacated|non_payment`; transfers counted once,
  separately; `cancelled` contracts appear nowhere (the S02 promise).
- **Daily close**: payments by `received_on` (manual) / settlement (rails), grouped
  method × employee-causer × site; cash subtotal is the drawer number.

Every report page footer links its section. Terms get es translations *in the doc*
(the operator reviews Spanish first — these words go in front of their accountant).

## The pattern

`App\Support\Reports\{Name}Report::run(ReportFilters $f): ReportResult` — pure query
classes; `ReportFilters` = `{site_ids?, from?, to?, as_of?}` validated once;
`ReportResult` = rows + column meta (type: money|percent|int|date, currency per money
column) so the table renderer, CSV exporter, and 04's dashboard consume one shape.
Money as strings with currency, grouped never summed (the standing rule enforced by
the result type: a money column *has* a currency; mixed input throws). Bounded-query
assertions are part of the pattern's base test case — every report inherits one.

**Endpoints:** `GET /api/reports/{name}` (+ `?format=csv`). The `canEdit` stopgap
guards; S17's caller list notes the whole namespace at once.

**CSV:** locale-aware (`;`+`,` for es per the employee's locale, `,`+`.` otherwise),
UTF-8 BOM (Excel's es needs it), filename `{report}-{site|all}-{range}.csv`. Print
CSS: landscape, repeated headers, the definitions footer.

## Acceptance criteria

- [ ] The doc exists, es-reviewed flag in PR; the open-decisions escape hatch
      recorded.
- [ ] Pattern + filters + result-type + base test case; a trivial demo report proves
      the pipeline (table + CSV + print) end-to-end.
- [ ] CSV opens clean in es-locale and en-locale Excel (manual PR check, both
      screenshots); BOM present; money columns numeric.
- [ ] Insights nav shell with the report index page.

## Tests required

| Test | Asserts |
|---|---|
| `ReportFiltersTest::validation_and_defaults` | One contract |
| `ReportResultTest::money_requires_currency_mixed_throws` | The type's teeth |
| `CsvExportTest::locale_bom_numeric` | The Excel interface |
| `ReportBaseTest::bounded_query_inheritance` | Every report inherits |
