# Demo World Seeder

## Goal

One command produces a **living facility**: ~350 contacts across the lifecycle, deals
in every stage, offers in every state, contracts in every status (active, pending,
notice, awaiting-signature incl. declined/expiring, ended, cancelled), a delinquency
book spread across every ageing bucket with overlocks and suspensions, hundreds of
messages across all four channels, twelve months of billing/occupancy history feeding
every S16 trend — and a printed **demo script** telling you which persona shows which
story.

## The architecture: simulate, don't stage

Everything in this product is derived state over append-only facts. Staged inserts
(raw rows in clever statuses) produce a world the invariants contradict: charges
without invoices, occupancies without contracts, ageing that doesn't sum, trends that
lie. The demo seeder instead **replays history**:

1. **Clock harness** — `Carbon::setTestNow` stepping day-by-day through ~14 months.
2. **Real services** — contacts/deals/offers/contracts created through the same
   support classes and controllers-worth of logic the app uses; `ContractSigning::
   complete`, reservation-convert, transfers, vacates, rate changes — never raw
   inserts past the model layer.
3. **Real jobs at each step** — `contracts:activate`, `billing:run`,
   `delinquency:run`, `access:sync` (fake provider), playbook execution via the
   harness-adjacent synchronous queue — the schedule, compressed.
4. **Synthetic external events through real processors** — Stripe webhooks, delivery
   events, inbound email/SMS/WhatsApp, e-sign events, door events: fabricated
   payloads fed to the *idempotent processing jobs* against the fake/seeded provider
   accounts. The processors are tested; the seeder is just another caller.

Result: every number the S16 reports show is *computed truth about a simulated
history* — the cross-surface fixtures pass on the demo data by construction.

## Two layers of population

- **The cast** (~18 named personas): hand-authored journeys hitting every demo story
  — Marcus Webb's upsize (the original mockup conversation, made real), the full
  delinquency ladder with a denied door, the remote signer, the promise-keeper, the
  WhatsApp window dance. These are the people you *show*.
- **The crowd** (~330 contacts): weighted archetypes (browser → lost, quick signer,
  steady tenant, slow payer, churned…) generated deterministically to fill the
  distributions below. These make the pages *full*.

Deterministic RNG throughout (the house rule); `DEMO_SEED` env overrides for variety.

## Target state matrix (post-seed, asserted by task 04)

| Domain | Distribution |
|---|---|
| Contacts | ~350: 60 prospects, 40 leads, 30 opportunities, 170 tenants, 45 past, 5 lost |
| Deals | every stage populated; ~25 open |
| Offers | draft / sent / viewed / accepted / expired all present; ~2 declined-ish paths |
| Contracts | ~180 active · 8 notice · 5 pending · 6 awaiting-signature (1 declined, 1 expiring ≤3d, 1 viewed) · ~60 ended (mix vacated/non-payment) · 5 cancelled |
| Transfers | ~12 historical (up/down/same mix) · rate changes ~20 applied + 3 scheduled |
| Discounts | ≥15 contracts with provenance (percent + free_time); Nadia 20% veteran; Amara in €0 free window |
| Delinquency | open cases in every bucket 1-7…60+; ~6 overlocked; 3 suspended; 2 paused; promise-flags present; cured history |
| Messages | 450–600 across email/SMS/WhatsApp/call; ≥40 threads with inbound; triage 4; suppressed 3 |
| Payments | all three rails represented; reversals; failed autopay; pending payment links |
| Invoices | full history incl. rectificatives; deposit settlements with payouts pending |

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Harness & synthetic-event injection](./00-harness.md) | 1.5 days |
| 01 | [The cast: persona journeys](./01-cast.md) | 1.5 days |
| 02 | [The crowd & the simulation loop](./02-simulation-loop.md) | 1.5 days |
| 03 | [Communications population](./03-communications.md) | 1 day |
| 04 | [Verification & the demo script](./04-verification-and-script.md) | 0.5 day |

## Risks

**Runtime.** 420 simulated days × jobs is real work; target < 5 minutes wall-clock.
Levers, in order: run heavy jobs (billing/delinquency) on their natural cadence not
daily where equivalent, scope syncs to touched contracts, batch the crowd's
uneventful days. Measure in 02; a 20-minute seeder dies of disuse.

**Existing seeders stay.** The per-sprint fixtures serve the test suite; the demo
world is opt-in (`php artisan demo:seed` on a fresh migrate). Never entangle them —
tests keep their small deterministic worlds.

**Determinism vs believability.** Names/amounts from faker under the fixed seed;
*journeys* from scripts. If a demo run ever differs from the last, the script sheet
lies — the determinism test in 04 is the guard.
