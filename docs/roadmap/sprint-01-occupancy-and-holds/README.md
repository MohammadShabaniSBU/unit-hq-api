# Sprint 01 — Unit Occupancy & Holds

## Goal

Make unit availability a **fact table with database-enforced non-overlap**, instead of a
derived scan over `contract_items` and `reservations`. Everything downstream — vacate,
transfer, the unit map, occupancy reporting, access control — depends on being able to ask
"who is in unit X on date D?" and get an authoritative answer.

## Why now

Three problems exist today and all three get worse with every sprint:

1. **Double-booking is possible.** Two concurrent contract signings for the same unit both
   pass an application-level availability check and both commit. There is no constraint that
   stops it, because occupancy lives on a polymorphic `contract_items` row.
2. **A unit has no state other than "rented or not".** Maintenance, damage, overlock, and
   staff use are all invisible. Overlock lands in S07 and needs somewhere to live.
3. **Occupancy history is not queryable.** "What was our occupancy on 1 March?" currently
   requires reconstructing from contract dates. Reporting in S16 needs a real timeline, and
   transfers in S02 need to write two occupancy rows rather than mutate one.

This does **not** violate invariant 5 ("never store derived state"). We are not caching
`is_available`. We are recording the *facts* — occupancy and holds — from which availability
is computed. The prohibition is on cached booleans, not on fact tables.

## Exit criteria

- [ ] Signing two contracts for the same unit over overlapping dates fails at the database
      level, not just in application code.
- [ ] `Unit::availableAt($date)` and `Unit::scopeAvailableBetween()` read only
      `unit_occupancies` and `unit_holds`.
- [ ] Seeders produce occupancies and holds covering all seven unit states plus the
      documented edge cases; `migrate:fresh --seed` runs clean and a test proves the seeded
      dataset contains no overlaps.
- [ ] A unit can be put into maintenance from the panel and disappears from availability
      without any contract existing.
- [ ] The unit map and units list show the real state (available / occupied / held /
      maintenance) sourced from the new tables.
- [ ] Cross-cutting decisions in `docs/roadmap/README.md` §4 are resolved and recorded.
- [ ] Project docs no longer describe Stripe Connect; a fresh Cursor session answers payment
      questions with the per-site model.

## Task order

Strictly sequential — each depends on the previous.

| # | Task | Est. |
|---|---|---|
| 00 | [Lock cross-cutting decisions](./00-lock-cross-cutting-decisions.md) | 0.5 day |
| 01 | [`unit_occupancies` table and exclusion constraint](./01-unit-occupancies-table.md) | 1 day |
| 02 | [`unit_holds` table (reservations, maintenance, overlock)](./02-unit-holds-table.md) | 1 day |
| 03 | [Availability rework and seed data](./03-availability-rework-and-seed-data.md) | 1 day |
| 04 | [Panel: unit state surfacing](./04-panel-unit-state.md) | 1 day |
| 05 | [Doc realignment: payments architecture](./05-doc-realignment-payments.md) | 0.5 day |

## Risks

**SQLite has no `EXCLUDE` constraint and no `daterange`.** Local dev and the test suite run
on SQLite; deployment is Postgres. Task 01 specifies the dual approach: Postgres gets the
real constraint, SQLite gets a unique-index approximation plus an application-level guard
with `SELECT ... FOR UPDATE`. Tests must cover the application guard so the suite is
meaningful on SQLite; a separate Postgres-only test asserts the constraint fires.

**Seeder quality is now the main risk.** There is no live data, so no backfill is needed —
but that also means the seeded dataset is the *only* dataset. If the seeders do not produce
every unit state and edge case, task 04 has nothing to render and the whole sprint is
verified against a fiction. Task 03 therefore treats seeders as a deliverable with their own
acceptance criteria and integrity tests, not as scaffolding. Seed through the guards so a
malformed generator fails loudly.

**Scope creep into S02.** Vacate and transfer are *not* in this sprint. This sprint only
creates the tables and makes existing flows write to them. Closing an occupancy on move-out
is S02.
