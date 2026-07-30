# Sprint 01 — Unit Occupancy & Holds

> **Revision 2.** Task 00 split into `00` + `00b`; task 05 premise inverted (payments and
> fiscal identity scope to `legal_entities`, not to sites); tasks 01–04 amended for
> date-boundary and currency rules. If you are holding revision 1, discard it.

## Goal

Make unit availability a **fact table with database-enforced non-overlap**, instead of a
derived scan over `contract_items` and `reservations`. Everything downstream — vacate,
transfer, the unit map, occupancy reporting, access control — depends on being able to ask
"who is in unit X on date D?" and get an authoritative answer.

Two cross-cutting concerns ride along because they get more expensive every sprint and
because this sprint writes the seed data that every later sprint verifies against:

- **Money must carry its currency.** The panel currently renders the same price row as
  `€196.72` on the Rates matrix and `£196.72` on the contract page. That is a live defect,
  not a theoretical one.
- **Dates must resolve through a site's timezone.** This sprint introduces four DATE columns
  and an availability query keyed on "today". Left implicit, the server's timezone becomes
  the de facto authority and every later date bug traces back here.

## Why now

Three problems exist today and all three get worse with every sprint:

1. **Double-booking is possible.** Two concurrent contract signings for the same unit both
   pass an application-level availability check and both commit. There is no constraint that
   stops it, because occupancy lives on a polymorphic `contract_items` row.
2. **A unit has no state other than "rented or not".** Maintenance, damage, overlock, and
   staff use are all invisible. Overlock lands in S08 and needs somewhere to live.
3. **Occupancy history is not queryable.** "What was our occupancy on 1 March?" currently
   requires reconstructing from contract dates. Reporting in S17 needs a real timeline, and
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
- [ ] Cross-cutting decisions D1–D8 are resolved and recorded in `10-open-decisions.md`.
- [ ] **No money value leaves the API without its currency, and no panel component renders a
      currency symbol literal.** The `€`/`£` mismatch on the same price row is gone.
- [ ] **No availability or expiry code calls `Carbon::today()` / `now()->toDateString()`
      directly.** Date boundaries resolve through the site timezone helper.
- [ ] Project docs describe **one** payments architecture: credentials and fiscal regime
      scope to `legal_entities`. A fresh Cursor session asked "where do Stripe keys live?"
      answers *per legal entity*, and does not mention Stripe Connect.

## Task order

Strictly sequential — each depends on the previous. `00b` **must** land before `03`, or the
seeders are written twice.

| # | Task | Est. |
|---|---|---|
| 00 | [Lock cross-cutting decisions](./00-lock-cross-cutting-decisions.md) | 0.5 day |
| 00b | [Currency integrity](./00b-currency-integrity.md) | 1 day |
| 01 | [`unit_occupancies` table and exclusion constraint](./01-unit-occupancies-table.md) | 1 day |
| 02 | [`unit_holds` table (reservations, maintenance, overlock)](./02-unit-holds-table.md) | 1 day |
| 03 | [Availability rework and seed data](./03-availability-rework-and-seed-data.md) | 1 day |
| 04 | [Panel: unit state surfacing](./04-panel-unit-state.md) | 1 day |
| 05 | [Doc realignment: payments & fiscal identity](./05-doc-realignment-payments.md) | 0.5 day |

Six days of work in a one-week sprint with no slack. If something has to give, `04` is the
only task whose absence does not block S02 — the API surface still exists and the panel keeps
showing "Enabled" for one more week. Do not drop `00b`; it is the task that makes the seed
data trustworthy.

## Risks

**SQLite has no `EXCLUDE` constraint and no `daterange`.** Local dev and the test suite run
on SQLite; deployment is Postgres. Task 01 specifies the dual approach: Postgres gets the
real constraint, SQLite gets an application-level guard with `SELECT ... FOR UPDATE`. Tests
must cover the application guard so the suite is meaningful on SQLite; a separate
Postgres-only test asserts the constraint fires.

**Seeder quality is the main risk.** There is no live data, so no backfill is needed — but
that also means the seeded dataset is the *only* dataset. If the seeders do not produce every
unit state, edge case, and currency, task 04 has nothing to render and the sprint is verified
against a fiction. Task 03 therefore treats seeders as a deliverable with their own
acceptance criteria and integrity tests. Seed through the guards so a malformed generator
fails loudly.

**Timezone drift is invisible until it isn't.** A `timestamp → date` cast in the server
timezone produces correct-looking data for months, then a reservation taken at 23:30 in
Madrid expires a day early. Task 00 introduces the helper; tasks 01–03 must actually use it.
Grep for `Carbon::today` in the review, not just read the diff.

**Scope creep into S02 and S03.** Vacate and transfer are *not* in this sprint. Neither is
the `legal_entities` table, the fiscal invoice model, or jurisdiction-aware tax resolution.
This sprint creates the occupancy tables, makes existing flows write to them, and writes
down decisions. Task 05 edits documentation only.

## Blocking question owned outside this sprint

**Is the Spanish client one legal entity with several sites, or several entities?** Blocks the
S03 schema. Recorded in `10-open-decisions.md` under "Undecided" with an owner and a date by
the end of task 00. If it is still open at S03 kickoff, S03 cannot start — say so out loud in
the retro rather than guessing at a single-entity schema.
