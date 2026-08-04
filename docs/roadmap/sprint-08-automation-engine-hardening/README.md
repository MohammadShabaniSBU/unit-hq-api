# Sprint 08 — Automation Engine Hardening

## Goal

Make the automation engine **provably correct**, because S09 builds two revenue-critical
playbooks (debt process, lead chase) directly on it. Three debts get paid: the `waiting`
status your own `WaitHandler` stub has cited since day one, branching correctness under a
fixture-driven harness, and the primitives playbooks need — real delayed waits,
cancellation, and a run-level **guard** (the "cancel everything when they pay" mechanism,
which is an exit condition, not a branch).

No new node types beyond finishing `logic.wait`. No playbook UI. Hardening only.

## Verified starting state

`AutomationExecutor` + handler map, `ConditionEvaluator`, `TriggerMatcher`,
`AutomationContext` loop suppression, `TokenResolver`, append-only runs/steps with
`root_run_id` retry idiom, skipped-branch logging — all present.
**RBAC (sprint 18):** while `AutomationContext` is active, `Actor::current()` resolves
to `SystemActor` — automation-originated writes do not require per-action permissions;
the operator authorised the automation graph. Causer stamping is unchanged (invariant 25).
`AutomationRunStatus`: pending/running/succeeded/failed/cancelled — **no `waiting`**.
`WaitHandler`: explicit stub. Branching: owner-reported "still has problems" — task 01
treats the evaluator as untrusted until the golden suite says otherwise.

## Exit criteria

- [ ] `logic.wait` parks a run as `waiting` with a resume time; resume continues exactly
      where it left off; the run-log never shows a parked wait as `running`
      (`10-open-decisions.md` entry closed).
- [ ] A run can be cancelled while `pending|running|waiting`, atomically, with cause;
      a run-level guard condition cancels automatically at every step boundary and on
      resume.
- [ ] The condition evaluator passes an exhaustive golden matrix (types × operators ×
      null/missing semantics) and its rules are written down.
- [ ] Delinquency and payment domain events trigger automations from queue/scheduled
      contexts with correct (possibly null) causers.
- [ ] `AutomationHarness` runs graph fixtures synchronously in tests; a committed fixture
      library covers every node type and every S09-needed shape.

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Run lifecycle: waiting, cancel, guard](./00-run-lifecycle.md) | 1.5 days |
| 01 | [Condition evaluator correctness](./01-condition-evaluator.md) | 1 day |
| 02 | [Trigger surface: delinquency & payments](./02-trigger-surface.md) | 1 day |
| 03 | [Harness & golden fixtures](./03-harness-and-fixtures.md) | 1 day |
| 04 | [Panel: run-log lifecycle](./04-panel-run-log.md) | 0.5 day |

03 depends on 00–02; write its fixtures *as* 00–02 land, finalize after.

## Risks

**Resume is a fresh worker with old context.** Everything a resumed run needs must live in
the run/step rows — invariant 25 already forbids re-resolving causers in workers; extend
the same discipline to trigger payload snapshots. If resume "just re-fetches the model",
a contact renamed mid-wait sends tomorrow's email to yesterday's assumptions — decide
snapshot-vs-live *per token class* explicitly (01/03 pin it).

**Guard ≠ branch.** A branch chooses a path once; the guard is re-evaluated at every
boundary and can kill a run mid-flight. Implementing the guard as a prepended branch node
is the tempting wrong move — it wouldn't fire during a 3-day wait. S09's cancel-on-payment
depends on this distinction.

**Cancellation races the executor.** A run cancelled while a worker is mid-step must not
half-execute the *next* step. The status check belongs inside the step-claim transition,
not at loop top.
