# S08-03 — Harness & golden fixtures

## Context

"Proven under test" needs machinery: a way to run a whole automation graph synchronously
in a test, from trigger payload to final run state, asserting the step sequence. Without
it, every engine test hand-builds runs and asserts fragments — which is exactly how the
branching problems survived this long. The harness is also S09's TDD tool: playbooks will
be *specified* as harness fixtures before their UI exists.

## Scope

**In:** `AutomationHarness` test utility, fixture format + loader, the golden fixture
library, queue-interaction fakes (time travel through waits), regression wiring.
**Out:** load/performance testing, panel snapshot testing.

## Behaviour

### The harness

`Tests\Support\AutomationHarness`:

```php
AutomationHarness::load('debt_wait_guard')        // fixture name → automation rows
    ->trigger('object_created', $delinquency)     // fires the real TriggerMatcher
    ->assertRunStatus(Waiting)
    ->travelTo('+3 days')                          // advances time, runs the sweeper
    ->assertStepSequence(['trigger','send_email','logic.wait','logic.branch','create_object'])
    ->assertStepStatus('logic.wait', Succeeded)
    ->assertSkipped(['branch.false.path'])
    ->mutate(fn() => $this->payAllOf($contract))   // then re-guard
    ->assertRunStatus(Cancelled, cause: 'guard');
```

Rules: it drives the **real** executor, matcher, evaluator and lifecycle — no mocked
internals; only time and queues are faked (`Queue::fake` selectively — delayed resume
jobs execute under `travelTo`, and the sweeper path gets its own no-delayed-job mode per
00's test). Every assertion failure prints the run's step table — debuggability is the
feature.

### Fixture format

`tests/fixtures/automations/*.json` — the bulk-graph PATCH payload shape (the format the
panel already saves), so fixtures are real graphs: exportable from the editor, importable
into it when debugging. Loader validates through the same server-side graph validation —
an invalid fixture fails loudly at load, not confusingly at execution.

### The library (each a named fixture + a test)

1. `linear_three_actions` — smoke.
2. `branch_true_false_pair` — both paths, skipped-logging asserted.
3. `nested_branch_3deep` — the 01 evaluator in situ.
4. `wait_relative_then_branch` — post-wait snapshot semantics (01's seam test rides this).
5. `wait_until_token` — datetime-token resume.
6. `guarded_wait_cancelled` — the S09 debt shape: act, wait, guard-cancel on payment.
7. `guarded_completes_when_guard_holds` — the same graph, guard never fires.
8. `create_object_chain` — created record as downstream `step_output` target.
9. `cancel_midwait_manual` — 00's race, harness-level.
10. `delinquency_open_to_cure_exit` — 02's triggers end-to-end: case opens → enrol →
    cure fires → guard cancels. **This fixture *is* the S09 debt playbook's skeleton.**
11. `retry_failed_autopay_recipe` — 02's documented recipe, executable.
12. `schedule_trigger_quiet_deal` — the lead-chase enrolment shape.

Fixtures 10–12 are the S09 head start: the playbook compiler's target output, specified
before the compiler exists.

### Regression wiring

The harness suite joins the default `php artisan test` run (it's fast — synchronous, no
real queues). Add a CI-visible count assertion: every registered node handler appears in
≥1 fixture (`HandlerCoverageTest`) — a new handler without a fixture fails the build,
which is how the library stays honest as S09 adds handlers.

## Acceptance criteria

- [ ] Harness API as sketched; failures print the step table.
- [ ] All 12 fixtures committed, loading through real graph validation, green.
- [ ] `travelTo` exercises both resume paths (delayed job present / sweeper-only).
- [ ] Fixture 10 demonstrates the full S07→S08 integration (real Delinquency row, real
      trigger, real guard cancel via the PaymentAllocator wiring example from 00).
- [ ] `HandlerCoverageTest` enforces fixture coverage of every handler.
- [ ] A fixture exported from the panel editor round-trips into the loader (manual check,
      noted in PR).

## Tests required

The library *is* the test list (12 named tests + `HandlerCoverageTest` +
`HarnessSelfTest::prints_step_table_on_failure`).
