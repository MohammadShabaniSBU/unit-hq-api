# Sprint 07 — Delinquency Engine

## Goal

Overdue contracts progress through a **configurable escalation ladder** — late fees
assessed, units overlocked, notices recorded — and **cure automatically** the moment money
arrives, releasing everything the ladder applied. This is the failure branch of the demo
spine: sign → bill → *don't* pay → fee → overlock → pay → everything clears itself.

EU framing (roadmap §regulatory): there is no lien-and-auction statute — recovery is
contractual retention + civil claim. So the engine models **facts and audit trail**
(fees, overlock, notices, a defensible timeline), and the ladder is *configuration*, never
hard-coded law. Jurisdictional differences are policy rows, not code branches.

## Boundary with S09 (respect it or build the playbook twice)

This sprint is the **state machine and the actions**. The *communications choreography* —
"day 1 email, day 2 SMS, day 3 urgent task, cancel all on payment" — is the S09 debt-process
playbook running on the automation engine, triggered by the states this sprint creates.
S07's `record_notice` action creates the notice **row** (mark-sent manual, as nothing can
send yet); S09+comms make sending automatic. Do not put channel logic, templates, or
send-scheduling in this sprint.

## Verified starting points

| Piece | State | Fate |
|---|---|---|
| `HoldType::Overlock` | Enum value, constraint-exempt, nothing writes it (S01 design note: "S07 sets it") | Task 03 activates it |
| `ChargeType::LateFee` | Exists (S01-00) | Task 02 assesses it |
| Per-charge overdue | Computed, invariant 5 | The *only* overdue truth; cases derive from it |
| `contract_notices` | **Not built** (S02 spec'd it; implementation skipped it) | Created in task 01, generic as originally designed |
| `PaymentAllocator` | Single path all three rails allocate through | Task 02's cure trigger hooks here |
| `autopay_attempts.failed` | S06 records codes | Panel context + S09 trigger input; engine itself keys off charges, not attempts |

## Exit criteria

- [x] A contract crossing its policy's day offsets gets exactly the configured actions,
      once each, idempotently, timezone-correctly, isolated per contract.
- [x] Payment through **any** rail (webhook, link, manual cash) triggers same-day
      re-evaluation: case cures, overlock auto-releases, timeline shows it.
- [ ] Fees never compound (no fee-on-fee base), are capped per case, and follow the
      fiscal defaults (0% tax, uninvoiced) behind gestor-flagged config.
- [x] An operator can pause a case (dispute), act manually (fee/overlock/release now),
      and every action lands on an append-only timeline.
- [x] `DelinquencySpineTest`: seeded contract → run → fee+overlock → cash payment →
      cured+released, executable end-to-end.

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Policies & settings](./00-policies-and-settings.md) | 1 day |
| 01 | [Cases, steps & notices](./01-cases-steps-notices.md) | 1 day |
| 02 | [Engine: run, fees & cure](./02-engine-run-fees-cure.md) | 1.5 days |
| 03 | [Overlock](./03-overlock.md) | 0.5 day |
| 04 | [Panel: board, timeline & manual actions](./04-panel-delinquency.md) | 1.5 days |

## Risks

**Severity is derived; the case is facts.** Days-overdue, current stage, amount overdue —
all computed live from charges (invariant 5). What gets *stored* is what happened: case
opened, step executed, fee charge id, hold id, notice id, cured. If a task wants a
`current_stage` column, it's re-deriving state as storage — the S01 `is_available` mistake
wearing a new coat.

**Grace vs offset confusion.** Offsets count from the **oldest unpaid charge's due date**
in site-local days; grace is just "no step configured before day N". One anchor, stated
everywhere, or operators will never trust the day math.

**Cure must beat the nightly run.** A tenant who pays cash at the counter must watch the
overlock flag clear before they walk to their unit — hence the allocator hook, not just
the daily job. Test the same-day path explicitly.

**Fee-on-fee is a legal trap, not a rounding preference.** Percentage bases exclude
`late_fee` (and deposits, adjustments) by construction, asserted in tests — compounding
penalty interest is unlawful in the deployed jurisdictions.
