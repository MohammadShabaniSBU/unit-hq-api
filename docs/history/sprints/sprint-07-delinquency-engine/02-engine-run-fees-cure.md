# S07-02 — Engine: run, fees & cure

## Context

The executor. A daily run (S05's engine posture: per-contract isolation, idempotent,
tz-correct) walks eligible contracts through their policy; an event-driven hook makes
cure instant when money lands. Late-fee assessment lives here because it's the one action
that writes the ledger.

## Scope

**In:** `delinquency:run`, per-contract evaluation transaction, due-step execution
dispatch (fee + notice + task here; overlock delegates to 03), the `PaymentAllocator`
cure hook, fiscal handling of fees.
**Out:** any sending (S09+comms), retry of failed autopay (S06's manual button stands;
automated retry is a candidate *playbook step*, not engine work).

## Behaviour

### The run

`delinquency:run` (scheduled daily; idempotency makes hourly safe if wanted) — reuse the
S05 shell pattern: eligibility query (active/notice_given, site policy set), per-contract
transaction with `FOR UPDATE` on the contract, then:

1. `DelinquencyState::isDelinquent`? No → cure the open case if any; done.
2. Yes → ensure case open (01 semantics). Paused → record nothing, next.
3. `elapsed = site-today − anchor_due_date` — **anchor, not opened_on**: detection lag
   (downtime) must not grant extra grace. Re-anchor check: if the anchor charge got paid
   but others remain, cure the case? No — the case stays open while *any* overdue charge
   exists; `daysOverdue` (oldest unpaid) drives display, but **step offsets evaluate
   against the case's `anchor_due_date`** — one clock per case, no goalpost motion when
   older charges clear partially. Pin this in comments + tests.
4. For each policy step with `offset_days <= elapsed`, unexecuted (the partial unique is
   the guard — insert-first, act-second, so a crash between leaves a step row without
   artefact rather than a double artefact; a `detail.incomplete` sweep flag surfaces
   those): execute in `sort` order.
5. Isolation + run observability: reuse the billing-runs tables? **No** — separate
   lightweight `delinquency_runs` mirrors would duplicate; instead each run logs Tier-2
   `billing` channel summary (`delinquency.run.completed`, counts) and per-contract
   failures Tier-1. The case timeline *is* the observability; a runs page adds little.

### Actions

**`assess_late_fee`:** base = `overdueBase()` at execution moment; flat or
`round2(base × percent/100)`; apply `cap_per_case` against Σ prior fee steps this case;
zero-or-capped-to-zero → step recorded with `detail.skipped_zero`. Charge:
`late_fee`, net = fee, tax per `fiscal.late_fee_tax` (default 0%), `due_date` = today,
no period, currency = contract; **uninvoiced** when `fiscal.invoice_late_fees` false
(default) — the charge simply never enters `InvoiceIssuer` selection (S03's filter gains
the type). Step row links the charge; money as strings in `detail`.

**`record_notice`:** insert `contract_notices` (type from params, `required_by` null,
unsent) + link. **`create_task`:** the automation engine's task defaults; link.
**`place_overlock`:** delegate to 03's helper; link the hold.

### The cure hook

`PaymentAllocator` (every rail's single path) dispatches `EvaluateDelinquency(contract)`
**after commit** of any allocation touching the contract. The job re-runs the same
per-contract evaluation — cure + 03's auto-release happen minutes after the counter
payment, not tomorrow. Also dispatched by write-off creation and the vacate transition.
Same-transaction would be wrong here (unlike the chain): cure is a *consequence*, and a
failed cure evaluation must not roll back a real payment — `afterCommit` + queue retry.

## Invariants

- 3/5/10 as ever; the insert-first execution rule above; **fee base excludes fees** —
  assert with a two-fee-steps fixture where fee #2's base ignores fee #1.

## Acceptance criteria

- [ ] Day-by-day fixture across a policy: each step fires exactly once at its offset,
      tz-correct, idempotent under re-runs and downtime catch-up (no extra grace).
- [ ] Anchor semantics: partial payments clearing the oldest charge don't move offsets.
- [ ] Fee math: flat, percent, cap, zero-skip, no-compound — bcmath-exact fixtures.
- [ ] Fees excluded from invoices by default; flag flips inclusion.
- [ ] Cash payment at 14:00 → cured + (03) released same afternoon, via the hook.
- [ ] Paused case: aging continues, execution doesn't; resume back-fills flagged.
- [ ] `DelinquencySpineTest`: seed → billing run → skip payment → delinquency runs →
      fee + overlock + notice → manual cash payment → cured, released, timeline complete.

## Tests required

| Test | Asserts |
|---|---|
| `EngineTest::ladder_day_by_day_idempotent` | Once-each under replay + catch-up |
| `EngineTest::anchor_not_moving` | Partial-payment goalposts |
| `FeeTest::flat_percent_cap_zero_nocompound` | The money table |
| `FeeTest::invoice_exclusion_flag` | Fiscal default |
| `CureHookTest::allocation_triggers_same_day_cure` | All three rails |
| `EngineTest::pause_resume` | 01 semantics through the engine |
| `DelinquencySpineTest::full_failure_branch` | The sprint's exit, executable |
