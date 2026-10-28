# Demo-02 — The crowd & the simulation loop

## Context

The loop that runs the world: 14 months of ticks executing cast scripts, crowd
archetype events, and the daily jobs — tuned to finish under ten minutes. The crowd
turns eighteen stories into a Madrid facility of ~800 contacts and ~80% occupancy.

## Scope

**In:** the master loop, crowd archetypes + weighted generation, historical texture
(rate changes, transfers, churn cohorts), runtime tuning + instrumentation.
**Out:** message texture beyond archetype-generated sends (03), verification (04).

## The crowd archetypes (deterministic weights)

| Archetype | ~n | Journey shape |
|---|---|---|
| Browser | 220 | Contact + deal, late enrolment; most stay open (quiet / lead-chase); few lost |
| Quick signer | 140 | Enquiry → offer → walk-in sign within days → steady payer; ~50% insurance |
| Considered signer | 240 | Offer → view → **accept (reservation)** → convert; some remote signatures; ~50% insurance |
| Steady tenant | (from signers) | Autopay or link-payer, zero drama — the occupancy backbone |
| Slow payer | 35 | Drifts 1-14 days late, playbook touches, always cures |
| Serious delinquent | 8 | Deep buckets; 2 reach overlock; 1 vacates non-payment |
| Churner | 80 | 3–12 month tenures ending in clean vacates across the timeline |
| Upsizer/downsizer | 12 | Historical transfers with rate deltas |
| Reservation pending | 32 | Offer accepted near seed-end; live hold |
| Reservation expired | 16 | Accepted, then expired (status + released hold) |
| Reservation cancelled | 10 | Accepted, then cancelled + lost |

Generation: signers enrol **early** so occupancy climbs toward ~80–85%; browsers enrol
**late** so the open-lead pool stays 250–350 at seed-end. Parameters (unit class,
payment behaviour offsets, amounts) from the seeded RNG. Archetypes compile to the
same day-script format as personas — one executor. Considered signers never skip the
reservation: offer accept writes the hold (invariant 12).

## The loop

Per tick: (1) due cast steps, (2) due crowd steps, (3) jobs in schedule order
(activate → billing → delinquency → access sync scoped → playbook sweep), (4) light
probabilistic texture (a crowd inbound reply, a door event for a random active
tenant — bounded per day). Sundays skip texture (realism, and a runtime lever).

**Runtime budget** (instrumented per phase, printed): billing/delinquency runs are
cursor/idempotency-cheap on no-op days by design — *trust but verify*; if the
14-month wall exceeds 10 minutes, levers in order: weekly delinquency ticks between
events, access sync only on nudge days (it's nudge-driven anyway; the hourly
authoritative pass becomes weekly in-sim), crowd texture batch-size. Record the
final timings in the PR.

## Acceptance criteria

- [ ] Full run (cast + crowd) lands the README matrix within tolerance bands
      (04 asserts exact rows for cast, ranges for crowd).
- [ ] Occupancy trend rises to ~85%; every ageing bucket populated; churn spread
      across the year (movement report non-degenerate).
- [ ] Deterministic: two runs, identical row counts + spot-checked ids/amounts.
- [ ] Wall-clock < 10 min on the dev machine, timings printed.
- [ ] Jobs run through their real entry points (the schedule-order list is code,
      not copy — assert against the Kernel's actual command list where feasible).

## Tests required

None in PHPUnit. Matrix bands, determinism, trends, and wall-clock budget are
checked manually on `php artisan demo:seed` (timings print; two runs with the
same `DEMO_SEED` should match). Soft target under 10 min; investigate if over 15.
