# S16-02 — Money: ageing, collections, daily close

## Context

The reports that pay for the product: where the money is stuck (ageing), how well it
un-sticks (collections — the S07/S09/S12 machinery graded), and whether the drawer
matches the ledger (daily close). All ledger-derived; the definitions live in 00's
doc.

## Scope

**In:** ageing report (contract + charge views), collections report (rates,
promise-kept, autopay recovery, playbook-touch context), deposit liability report
(small, accountant-driven), daily close. **Out:** forecasting, fee-income
profitability, Verifactu-period fiscal summaries (arrive with the Spain pack).

## Ageing

Contract view: row per contract with overdue — buckets across columns (oldest-unpaid
bucketing per 00), amount split rent/fees/other, case stage + last step, last
payment, promise flag (an open `payment_promised` wrap-up inside its N-day window),
enrolment state. Charge view: totals per bucket per type. **The reconciliation
fixture**: both views' grand totals equal, to the cent, and equal the delinquency
board's chip (another cross-surface pin — the board, the report, one number).

## Collections

Period-filtered: charged vs collected by type (the 00 rate), then the graded
machinery — autopay first-attempt success / eventual recovery; promise-kept rate
(and its inverse: promises broken, the call-back list — row-level drill to
contracts); average days-to-cure from case open; per-step-type context (cases that
received an overlock vs not — **correlation labelled as such on the page**, not
causation; small print matters when a number implies "overlock works").

## Deposit liability

One table the accountant asks for quarterly: deposits held (active contracts'
snapshot amounts), pending payouts (`deposit_settlements.payout_status = pending` —
the S07-consumable rows, still consumable), per site, per currency. Two minutes to
build on `deposit_settlements`; disproportionate goodwill.

## Daily close

Per site per day: payments grouped method × recording-employee (causer for manual;
`system` for rails), with reversals shown negative and netted subtotals; the **cash
subtotal is the drawer number** — the page says so. Rail payments show their
provider refs for spot-checking. A close-mismatch has no workflow v1 (no "confirm
close" state — recorded as the natural follow-up; today the report *is* the check).
Date defaults today, site defaults the operator's current selector.

## Acceptance criteria

- [ ] Ageing: bucket math per the 00 definitions on constructed seeds (boundary
      days!), both views reconcile, board-chip consistency fixture green.
- [ ] Collections: each rate hand-verified on a seeded quarter; promise-kept window
      respected; the correlation caveat rendered.
- [ ] Deposit liability ties to contract snapshots + settlements exactly.
- [ ] Daily close: a seeded messy day (cash ×3, two employees, one reversal, one
      autopay, one link payment) nets to the fixture; reversals visibly negative.
- [ ] All: filters, CSV, print, bounded, currency-grouped.

## Tests required

| Test | Asserts |
|---|---|
| `AgeingTest::buckets_boundaries_reconcile_board` | The pins |
| `CollectionsTest::rates_promise_recovery_fixtures` | The grades |
| `DepositLiabilityTest::ties_to_snapshots` | The accountant's number |
| `DailyCloseTest::messy_day_nets` | The drawer |
