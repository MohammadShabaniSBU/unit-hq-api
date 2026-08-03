# SEED-04 — Verification & the demo script

## Context

Two closers: the test that proves the seeded world is *true* (every count in range,
every invariant holding, every cross-surface figure agreeing — on this data, not
just fixtures), and the printed **demo script** that turns 350 contacts into a
guided tour: which persona shows which story, where to click, what number to point
at.

## Scope

**In:** `DemoWorldVerificationTest`, invariant sweeps over the seeded set, runtime
budget, command ergonomics (`demo:seed --fresh`, production guard), the script
printer, docs. **Out:** any new world content — this task only proves and narrates
what 00–03 built.

## Verification

**The matrix, asserted.** Every row of the README target table becomes a
range-assertion (`~180 active` → `170..190`); every *status/state enum in the
product* gets an existence assertion — contracts (all 7 statuses), offers, deals,
delinquency buckets (each of the 5 non-empty), holds (all types incl. 1+ live
overlock), envelope states, grant states, message statuses, triage/suppression
counts. A status with zero rows is a demo page with an empty tab — the S01 seeder
philosophy at world scale.

**Invariant sweeps (reused patterns, new scope):** no overlapping occupancies or
blocking holds anywhere; every non-awaiting contract has its occupancy; ageing's
two views reconcile and equal the board chip **on the demo data**; the three
occupancy surfaces agree; every dashboard card equals its report; balance identities
(Σ charges − Σ payments = Σ computed balances) to the cent across ~250 contracts'
history; every message has provenance; every playbook enrolment's guard state is
consistent with its subject. These are the S16 consistency fixtures re-run against
the big world — the strongest single claim the seeder makes.

**Ergonomics.** `php artisan demo:seed {--fresh}`: `--fresh` runs
`migrate:fresh` first; refuses in production (`app()->isProduction()` hard stop);
deterministic by default (`DEMO_SEED` env varies); **runtime budget: < 5 minutes**
asserted in CI-adjacent test (the day loop is ~420 iterations of mostly-cheap
work — if it creeps, the budget test names the slow day). The per-sprint test
seeders remain untouched and independent (grep: `DatabaseSeeder` unchanged).

## The demo script

Seed end prints (and writes `storage/demo-script.md`):

- **The cast index**: persona → one-line story → entry point. *"Marcus Webb — upsize
  transfer with retained rate → Contacts → Marcus Webb → Contracts tab, show two
  occupancies on one contract."* *"Lucía Fernández — day-14 delinquency, overlocked,
  door denied yesterday, promise recorded → Delinquency board row 1 → case timeline,
  point at the denied-entry Interaction."* *"The remote signer — awaiting contract,
  viewed 2d ago, expiring in 3 → Contracts → Awaiting tab."*
- **The tour order** (suggested 15-minute path): Dashboard (point: economic vs unit
  occupancy gap) → Inbox (the 7 unread, the open WhatsApp window, request-payment
  round trip on the staged thread) → Delinquency board → Lucía's case → Unit map
  (overlock glyphs) → Rent roll → Funnel (walk-in vs remote split).
- **The numbers that must match** while presenting: occupancy on three surfaces,
  ageing total = board chip — pre-printed so the presenter can *show* consistency.

## Acceptance criteria

- [ ] Verification test green on a fresh seed: matrix ranges, existence sweep,
      every invariant/consistency assertion; budget under 5 minutes.
- [ ] Production guard + `--fresh` + determinism (two runs, identical script
      output); `DEMO_SEED` varies the crowd, never the cast.
- [ ] Script prints + persists; every cast entry's click-path manually walked once
      (PR checklist with the 15-minute tour timed).
- [ ] Docs: `README.md` (repo) quickstart gains the demo command;
      `10-open-decisions.md` notes the seeder as the demo baseline.

