# Sprint 05 — Recurring Billing

## Goal

**Month two bills itself.** A daily, idempotent, observable billing run advances every
eligible contract's `billed_through` cursor, writing the period's charges and issuing the
period's invoice in one transaction per contract — surviving downtime by catching up,
isolating per-contract failures, and picking up scheduled rate changes with zero special
code.

This is the demo-critical sprint: it converts the product from "a pipeline that opens
tenancies" into "software that runs a facility". After it, the S07 delinquency story has
overdue charges to chase and the whole recurring narrative works end to end.

## What earlier sprints already solved (do not rebuild)

| Question the job must answer | Answered by |
|---|---|
| What is this contract's price for period starting D? | `Contract::itemsOn(D)` + `price_id` (S02-00/00a) — **scheduled rate changes need no code here** |
| What are the period boundaries? | Cadence/anchor snapshots on the contract + `BillingMath` (existing) |
| What day is "today" for this contract? | `SiteClock` (D8) — bare `now()`/`today()` remains a defect |
| How does the invoice get issued? | `InvoiceIssuer` (S03) — same path as signing; deposits excluded, kind logic, gapless numbers, (parked) Verifactu hook all inherited |
| What happens near move-out? | S02 vacate's later-of settlement — the job only needs a *stop line* |

## Exit criteria

- [ ] `php artisan billing:run` bills every contract whose next period start falls within
      the horizon; a second immediate run bills nothing.
- [ ] A contract with a scheduled future rate change bills the old amount before the
      effective date and the new amount after, with **no rate-change-aware code in the job**.
- [ ] Three days of downtime → next run generates three periods per affected monthly...
      (per cadence) contract, in order, each with its own charges + invoice.
- [ ] One contract failing (e.g. fiscal blocker) is recorded and skipped; the run completes;
      the contract retries next run.
- [x] `pending` contracts auto-activate on their move-in date; `notice_given` contracts stop
      being billed past the stop line; `ended` never bill.
- [ ] Billing runs are inspectable in the panel down to per-contract outcomes.

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Period arithmetic](./00-period-arithmetic.md) | 1 day |
| 01 | [Run engine & observability tables](./01-run-engine.md) | 1.5 days |
| 02 | [Per-contract generation rules](./02-generation-rules.md) | 1 day |
| 03 | [Activation, scheduler & horizon](./03-activation-and-scheduling.md) | 0.5 day |
| 04 | [Panel: billing runs & next-bill surfaces](./04-panel-billing-runs.md) | 1 day |

## Risks

**Idempotency lives in the cursor + lock, nowhere else.** The design has exactly one
mechanism: `SELECT … FOR UPDATE` the contract row, read `billed_through`, write everything,
advance the cursor, commit. No dedup tables, no "already billed?" heuristics — those are
how double-billing bugs hide. If a task seems to need a second mechanism, the first is
being used wrong.

**Catch-up must terminate.** A contract with a corrupted cursor far in the past could
generate hundreds of periods. Cap periods-per-contract-per-run (config, default 12) and
flag the cap being hit as a failure-for-review, not silent success.

**The job must not bill what vacate will settle.** The stop line (task 02) is the one
place S02 and S05 touch; get its boundary test right or tenants get billed for periods
their settlement then credits — correct money, terrible experience.

**Clock discipline under catch-up.** "Which periods are due" is evaluated against each
*site's* today. A UTC-midnight cron serving a Madrid site is off by hours; the run
evaluates eligibility per contract via `SiteClock`, and the scheduler just runs "often
enough" (daily is fine; hourly is safe — idempotency makes frequency a non-issue).
