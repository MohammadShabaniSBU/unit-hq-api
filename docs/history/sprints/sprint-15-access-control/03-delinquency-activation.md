# S15-03 — Delinquency & lifecycle activation

## Context

The payoff moment two phases in the making: S07 reserved `revoke_access`; S07's
overlock became a fact with no digital consequence; the cure hook restores everything
else. This task removes the reservation, wires the facts, and closes the loop — with
almost no new machinery, which is the architecture's report card.

## Scope

**In:** `revoke_access` step live (suspension writer), cure auto-restore (policy
flag), overlock's digital consequence verified end-to-end, manual suspend/restore
actions on the case, vacate/transfer interplay asserted, seeder + spine extension.
**Out:** per-point suspensions, schedule-based restrictions (after-hours rules —
provider-side territory, recorded).

## Behaviour

**The step.** Delete the reserved-rejection in the two policy controllers
(the S07 422s located in review); `revoke_access` params: `{}` v1. Execution
(the delinquency engine's action dispatch, S07-02 idiom): 
`AccessSuspension::suspend(contract, reason: delinquency, case)` — idempotent
(already-suspended → step recorded with `detail.already_suspended`, the
skipped-zero pattern), step row links the suspension id, the 00 helper's nudge does
the rest. **No provider code here** — the step writes a fact; 02 projects it. That
sentence is the acceptance test.

**Restore on cure.** `delinquency_policies` gains `auto_restore_access BOOLEAN
DEFAULT true` beside its overlock sibling; the S07 cure path (which already releases
overlocks per flag) additionally lifts delinquency-reason suspensions when set —
same transaction, same `cure`-trigger step row, same policy-flag-false behaviour
(pending-restore until manual). Manual suspensions (below) are **never** auto-lifted
by cure — an operator suspended for a reason cure doesn't know.

**Manual actions.** The S07 case manual-actions menu gains Suspend access / Restore
access (reason required, `trigger=manual` step rows, the Tier-3 from 00). Also
exposed on the contract page for non-delinquency use (dispute, safety) — reason
`manual`, visible in the same audit stream.

**Lifecycle interplay (assertions, not new code).** Vacate: occupancy close makes
desired-state drop the grants — but an unlifted suspension on an `ended` contract is
dead weight; the vacate transition lifts active suspensions with `lift_reason:
vacated` (one line in the S02 vacate path + nudge). Transfer: the door grant follows
the occupancy swap via 02's nudge; suspensions ride the contract untouched (a
suspended tenant transferring stays suspended — assert it, someone will assume
otherwise). Notice-withdrawal, re-delinquency-new-case: covered by the truth table +
nudges; fixtures anyway.

**Spine.** `DelinquencySpineTest` extends: … → `revoke_access` step → desired-state
denies → (fake adapter) revoked → denied event lands the timeline Interaction →
cash payment → cure → suspension lifted + overlock released → grants re-applied.
The full collections story, now with doors.

## Acceptance criteria

- [ ] Policy editor accepts the step (the 422 gone, the disabled option enabled in
      the S07 UI with its tooltip updated); execution writes suspension + step,
      idempotently, **zero adapter references in delinquency code** (grep-as-test).
- [ ] Cure restores per flag; flag-false shows pending-restore; manual suspensions
      survive cure; manual actions audit.
- [ ] Vacate lifts with reason; transfer preserves suspension while moving grants.
- [ ] Ladder demo: suspend day 8, overlock day 12 — two distinct facts, two distinct
      timeline entries, one denied door.
- [ ] The extended spine test green end-to-end.

## Tests required

| Test | Asserts |
|---|---|
| `RevokeAccessStepTest::fact_only_idempotent` | + the grep-as-test |
| `CureRestoreTest::flag_matrix_manual_survives` | The asymmetry |
| `LifecycleInterplayTest::vacate_lifts_transfer_preserves` | The assumptions |
| `DelinquencySpineTest` (extended) | The story, with doors |
