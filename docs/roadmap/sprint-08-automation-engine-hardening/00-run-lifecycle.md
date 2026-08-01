# S08-00 — Run lifecycle: waiting, cancel, guard

## Context

Three lifecycle primitives, one task, because they share the same state machine.
`waiting` closes the oldest open decision; cancellation and the guard are what S09's
"any step is cancelled if the person pays" actually is — not branches, an enrolment-level
exit condition evaluated at every boundary.

## Scope

**In:** `Waiting` status + transitions, real `WaitHandler` (relative + absolute waits),
resume job, cancel API + internals, run guard (condition tree + evaluation points),
step-claim atomicity.
**Out:** business-hours/weekday wait windows (S09 playbook param if needed — record),
per-step timeout policies, new node types.

## Schema changes

```sql
ALTER TABLE automation_runs ADD COLUMN guard JSONB NULL;          -- condition tree, evaluator syntax
ALTER TABLE automation_runs ADD COLUMN cancel_cause VARCHAR(32) NULL;
    -- manual | guard | superseded | trigger_object_deleted
ALTER TABLE automation_runs ADD COLUMN cancelled_by BIGINT NULL REFERENCES employees(id);
ALTER TABLE automation_runs ADD COLUMN waiting_until TIMESTAMPTZ NULL;
-- run steps: logic.wait steps store resume_at + resumed_at in existing detail/output json
CREATE INDEX ar_waiting_idx ON automation_runs (waiting_until) WHERE status = 'waiting';
```

`AutomationRunStatus` gains `Waiting = 'waiting'`. Legal transitions (enforce in one
`RunLifecycle::transition()` helper, everything goes through it):
`pending→running`, `running→waiting|succeeded|failed|cancelled`,
`waiting→running|cancelled`, `pending→cancelled`. Terminal states reject everything —
the contract-status idiom (S02-01) reapplied.

## Behaviour

**Wait.** `logic.wait` params: `{ "mode": "relative", "amount": 3, "unit":
"minutes|hours|days" }` or `{ "mode": "until", "expression": "<token>" }` (a resolvable
datetime token — e.g. a charge due date; resolution errors fail the step, not the world).
Handler: write the wait step (`status = succeeded` is wrong — introduce step status
`waiting` too, flipped to `succeeded` at resume so the log reads truthfully), set run
`waiting` + `waiting_until`, **release the worker** (return a Parked signal the executor
maps to a clean job exit; no sleep, no held worker). Resume: `ResumeAutomationRun`
dispatched `->delay()`ed at park time *and* a per-minute sweeper over the partial index
(belt/braces: a lost delayed job must not strand runs — the sweeper is authoritative,
the delayed job is latency optimization). Resume path: guard check → transition
`waiting→running` → executor continues **from the stored cursor** (next node id persisted
on the run at park; never re-walk from the trigger).

**Cancel.** `POST /api/automation-runs/{id}/cancel` (manual) and
`RunLifecycle::cancel(run, cause)` (internal). Atomicity: cancellation and step
execution both funnel through a **claim update** —
`UPDATE automation_runs SET status='running', current_node=? WHERE id=? AND status IN
('pending','waiting')` / cancel's equivalent — whoever's conditional update wins, the
loser observes and stops. No advisory locks needed; the row is the lock. Cancelled runs
append a synthetic step (`action = 'run.cancelled'`, cause, causer) so the log explains
itself; downstream unexecuted nodes are **not** back-filled as skipped (they never
evaluated — skipped means "branch chose otherwise", keep the vocabulary honest).

**Guard.** `guard` on the run (set at enrolment time by whoever starts the run — S09
sets it; the generic trigger path leaves it null). Evaluated via `ConditionEvaluator`
against the run's subject: (a) before each step claim, (b) at wait resume before the
transition, (c) opportunistically by `EvaluateRunGuards(subject)` — a tiny job any
domain code may dispatch after relevant writes (S09 wires PaymentAllocator→it; this
sprint just ships the job + one wiring example in tests). Guard true → nothing;
guard **fails** → `cancel(cause: guard)`. Evaluation errors (deleted subject) →
`cancel(trigger_object_deleted)` — a dangling run is worse than a conservative cancel.

## Invariants

Add to `09`:

> **Automation run transitions are single-funnel and claim-based.** Every status change
> goes through `RunLifecycle::transition()`; executors and cancellers race via
> conditional updates on the status column, never checks-then-writes. A parked wait is
> `waiting`, never `running`. Cancelled runs record cause and a synthetic step; unexecuted
> nodes are not marked skipped.

## Acceptance criteria

- [ ] Relative and until-waits park (`waiting`, worker released), resume on time via
      delayed job, and via sweeper alone when the delayed job is dropped.
- [ ] Resume continues from the cursor — a 5-node graph with a mid-wait executes nodes
      4–5 only.
- [ ] Cancel wins/loses the claim race correctly under a forced interleaving test;
      mid-wait cancel never resumes.
- [ ] Guard cancels at each of the three evaluation points; null guard is free of
      overhead (no evaluator call).
- [ ] Run-log JSON reads truthfully: wait step `waiting`→`succeeded`, synthetic cancel
      step, no phantom skips.
- [ ] `10-open-decisions.md` waiting-status entry moved to Decided.

## Tests required

| Test | Asserts |
|---|---|
| `WaitTest::park_release_resume_relative_and_until` | Both modes, worker freed |
| `WaitTest::sweeper_rescues_lost_delayed_job` | Authoritative path |
| `WaitTest::resume_from_cursor_only` | No re-execution |
| `CancelTest::claim_race_interleavings` | Conditional-update semantics |
| `GuardTest::three_evaluation_points` | Step / resume / event job |
| `GuardTest::deleted_subject_conservative_cancel` | Error posture |
| `LifecycleTest::transition_matrix` | Table-driven, terminal rejection |
