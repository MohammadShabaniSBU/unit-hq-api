# S05-03 — Activation, scheduler & horizon

## Context

Two small pieces without which the engine idles: nothing currently flips
`pending → active` at move-in (a billing job that only bills `active` would skip every
advance booking forever), and nothing runs the job. Plus the one new org setting the
horizon needs.

## Scope

**In:** activation transition job, scheduler wiring for both jobs, `billing_horizon_days`
setting, manual-run endpoint.
**Out:** notification of activation to anyone (comms phase), per-site run schedules
(idempotency makes a single global schedule sufficient).

## Behaviour

### Activation

`contracts:activate` (scheduled hourly): for each `pending` contract where site-today
(`SiteClock`) `>= move_in_date` → `ContractTransition::assert` + transition to `active`,
core activity `contract.activated`. Hourly, not daily, so a Madrid midnight move-in is
active before any billing evaluation that day; idempotent trivially (state machine refuses
repeats). Failure isolation per contract as in 01.

Note the ordering guarantee that matters: activation must run **before** billing in the
schedule (or simply more frequently) — a contract activating at 00:05 and billing at 02:00
is fine; the reverse loses a day. Document in `Kernel` comments.

### Horizon

`BillingSettings.billing_horizon_days` (SMALLINT, default 0): bill periods whose start is
within `site-today + horizon`. 0 = bill on the day the period starts (advance billing —
due that day). Operators who invoice N days ahead set N. Snapshot **not** required — the
horizon is operational, not contractual; it may change freely and only affects *when* a
period is generated, never its window or amounts (state this in the settings help text,
it is the reason this one setting is exempt from the invariant-18 snapshot rule).

Settings → Billing gains the field with that explanation.

### Scheduling & manual runs

`routes/console` / `Kernel`:

```php
Schedule::command('contracts:activate')->hourly();
Schedule::command('billing:run --trigger=scheduled')->hourly();   // idempotent ⇒ cheap
```

`POST /api/billing-runs` `{ dry_run?: bool }` → dispatches (or dry-runs) with
`trigger=manual`, `created_by` = employee; returns the run id (or the dry-run table).
Guarded by the existing `canEdit`-era stopgap helper — one more caller for S17 to fix,
noted in `10-open-decisions.md`'s stopgap entry.

## Acceptance criteria

- [x] `pending` with move-in today (site tz) activates; tomorrow's does not; a
      cancelled-pending never does.
- [x] Timezone fixture: two sites 12h apart activate on their own local dates.
- [x] Horizon 0 vs 3: periods starting in 2 days bill only under the latter; amounts
      identical (horizon-independence test).
- [x] Manual endpoint audited with causer; dry-run returns the table without a run row.
- [x] Scheduler entries exist with activation ≥ billing frequency (architecture test on
      the schedule list).

## Tests required

| Test | Asserts |
|---|---|
| `ActivationTest::flips_on_site_local_date` | Incl. tz fixture |
| `ActivationTest::idempotent_and_isolated` | Repeats refuse cleanly |
| `HorizonTest::gates_when_not_what` | Same window/amounts either way |
| `ManualRunTest::causer_and_dry_run` | Audit + no-write |
| `ScheduleTest::activation_not_slower_than_billing` | Ordering guarantee |
