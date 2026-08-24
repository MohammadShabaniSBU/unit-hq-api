# SEED-04 — Verification & the demo script

## Context

Two closers: a **manual** check that the seeded world matches the target matrix
(counts in range, stories present, cross-surface figures agreeing on this data),
and the printed **demo script** that turns ~800 contacts into a guided tour: which
persona shows which story, where to click, what number to point at.

There is **no** PHPUnit suite for the demo world (`DemoHarnessTest`,
`PersonaSmokeTest`, `SimulationTest`, and `DemoWorldVerificationTest` are all
gone). CI covers product features one-by-one; `demo:seed` is a presenter/dev tool.

## Scope

**In:** command ergonomics (`demo:seed --fresh`, production guard), the script
printer, docs, manual verification walk. **Out:** any new world content — this
task only proves and narrates what 00–03 built. **Out:** any demo-world PHPUnit
class.

## Verification

**The matrix (manual).** Every row of the README target table is a presenter
check after `demo:seed` (`~450 active` → eyeball the band; every status/state
that should be non-empty has rows). DISC-02 discounts (Nadia / Amara) are walked
from the cast index; product discount rules stay in `tests/Feature/Discounts/*`.

**Invariant sweeps (manual / presenter):** no overlapping occupancies or
blocking holds; ageing views reconcile with the board chip; the three occupancy
surfaces agree; dashboard cards equal reports; balance identities to the cent.
Walk them from the script — do not re-run as a multi-minute PHPUnit class.

**Ergonomics.** `php artisan demo:seed {--fresh}`: `--fresh` runs
`migrate:fresh` first; refuses in production (`app()->isProduction()` hard stop);
deterministic by default (`DEMO_SEED` env varies); **runtime budget: under 10
minutes** (timings print — if it creeps, name the slow phase). The per-sprint
test seeders remain untouched and independent (grep: `DatabaseSeeder` unchanged).

## The demo script

Seed end prints (and writes `storage/demo-script.md`):

- **The cast index**: persona → one-line story → entry point. *"Marcos Vega — upsize
  transfer with retained rate → Contacts → Marcos Vega → Contracts tab, show two
  occupancies on one contract."* *"Lucía Ferrer — day-14 delinquency, overlocked,
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

- [ ] Fresh `demo:seed`: matrix bands look right, cast stories present, budget
      under 10 minutes (timings printed). No demo-world PHPUnit class in the suite.
- [ ] Production guard + `--fresh` + determinism (two runs, identical script
      output); `DEMO_SEED` varies the crowd, never the cast.
- [ ] Script prints + persists; every cast entry's click-path manually walked once
      (PR checklist with the 15-minute tour timed).
- [ ] Docs: `README.md` (repo) quickstart gains the demo command;
      `10-open-decisions.md` notes the seeder as the demo baseline.
