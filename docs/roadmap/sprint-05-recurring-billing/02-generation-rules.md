# S05-02 — Per-contract generation rules

## Context

What actually gets written for one due period: charges per item, one invoice, cursor math
already handled by 00/01. Almost everything here is *reuse* — the task exists to pin the
rules at the seams (stop line, currency, fiscal failure) rather than to build machinery.

## Scope

**In:** the period generator called by 01's shell; stop-line semantics; fiscal-blocker
handling; seeder update (contracts with past-due cursors so the first real run has work).
**Out:** payment collection (S06 autopay consumes the same due charges), late fees (S07 —
due_date is what it will read), deposit anything (deposits are signing-time only).

## Behaviour

`App\Support\Billing\RecurringBilling::generatePeriod(Contract $c, array $window): PeriodResult`
— called inside 01's per-contract transaction, once per due window, in order:

1. **Stop line** (the S02 seam). `stop = max(scheduled_move_out_on,
   notice_given_on + notice_period_days)` — note this is the *same expression* as S02's
   final billing date, deliberately. Because `nextPeriod` always starts at the cursor,
   `window.start >= stop` ⟺ `billed_through >= stop` — so the stop line is a **condition,
   never a cursor operation**. Rules:
   - The 01 shell pre-checks it for `notice_given` contracts and records
     `skipped/stop_line` without calling `nextPeriod` (see 01).
   - Inside a catch-up loop, stop iterating when the next window's start `>= stop`;
     windows already billed this transaction stand, cursor = last billed end, outcome
     `billed`.
   - **Never write the cursor at the stop line** — not to `stop`, not anywhere. The cursor
     moves only to a billed period's end. A forced write here would (a) violate 00's
     boundary invariant, (b) break notice *withdrawal* (which must resume catch-up from a
     truthful cursor), and (c) corrupt S02's vacate gap-charge, which trusts
     `billed_through` to mean "actually billed through".
   Periods *straddling* the stop line bill in full — vacate's `move_out_settlement`
   policy credits the tail; that is S02's contract, don't duplicate it here.
2. **Items**: `$c->itemsOn($window['start'])` — one version per subject guaranteed by
   S02-00. Per item: net = the referenced price's amount (full period — stubs don't exist
   here), tax via the item's snapshot (`tax_rate_snapshot`), gross via `applyTax`; charge
   row: `charge_type` by subject (rent/insurance), `period_start/end`,
   `due_date = window.start` (billing in advance — decided), `net/tax/amount`, currency
   from the price, `contract_item_id`.
3. **Currency sanity**: all items' price currencies must match; mismatch throws (a
   config error, not a billable state) → `failed/currency_mismatch`.
4. **Invoice**: `InvoiceIssuer::issue($c, $charges)` — everything from S03 applies
   unchanged. A fiscal refusal (e.g. `simplified_limit_exceeded`) throws → 01 records
   `failed/fiscal_blocker` with the message; **the whole period rolls back** (no charges
   without their invoice), and the contract retries every run until the operator completes
   the tenant's fiscal data. The run-detail panel (04) makes these loud.
5. Return counts/amounts for the run item.

**What is deliberately absent:** proration (no stubs mid-life), discount logic beyond what
the item's price already embodies (the discount model remains open in
`10-open-decisions.md` — when it lands, it lands in item versions, not in this job),
per-contract cadence overrides (still none in v1).

## Seeder

- Rewind `billed_through` on a majority of seeded active contracts by 1–3 periods so
  `billing:run` on a fresh seed bills visibly.
- One contract per interesting state: fiscal-incomplete tenant over the simplified limit
  (fails), `notice_given` before its stop line (bills), past it (skipped/stop_line, cursor untouched),
  a scheduled rate change straddled by the rewound window (bills both amounts),
  currency-mismatch (fails) — each printed in the seeder summary like S01's edge cases.

## Invariants

- Invariant 20 family — charges + invoice + cursor: one transaction (01's shell).
- Invariant 18 — everything read from contract snapshots; org settings never consulted.
- Ledger + pricing rules as everywhere: amounts through `price_id`, bcmath, strings in
  activity.

## Acceptance criteria

- [ ] A due period yields per-item charges with correct net/tax/gross, due_date =
      period start, and one invoice covering exactly them.
- [ ] Rate change straddle: two consecutive periods bill old then new amount, untouched
      job code (the S02 payoff test — name it exactly that).
- [ ] Stop line: skip leaves the cursor untouched; straddling period bills full and the
      loop halts after it.
- [ ] Notice withdrawal after a stop-line skip resumes catch-up from the truthful cursor.
- [ ] Cross-sprint fixture: notice → run → vacate; the settlement's gap/credit math
      agrees with what the run actually billed under both `none` and `daily` policies.
- [ ] Fiscal blocker rolls back the entire period and retries next run.
- [ ] Currency mismatch fails that contract only.
- [ ] Fresh seed + `billing:run` produces the printed expectations.

## Tests required

| Test | Asserts |
|---|---|
| `GenerationTest::period_charges_and_invoice` | Amount/tax/due-date/coverage |
| `GenerationTest::s02_payoff_rate_change_straddle` | No special-case code (grep guard) |
| `GenerationTest::stop_line_never_writes_cursor` | Skip + straddle + loop-halt, cursor untouched |
| `GenerationTest::notice_withdrawal_resumes_catch_up_from_truthful_cursor` | Boundary intact |
| `GenerationTest::vacate_gap_charge_agrees_with_cursor` | S02↔S05 seam fixture |
| `GenerationTest::fiscal_blocker_atomic_retryable` | Rollback + next-run retry |
| `GenerationTest::currency_mismatch_isolated` | failed/currency_mismatch |
| `GenerationTest::insurance_items_billed` | Not rent-only |
