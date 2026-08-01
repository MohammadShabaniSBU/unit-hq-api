# S07-04 — Panel: board, timeline & manual actions

## Context

The operator's collections desk. Three surfaces: a **board** answering "who owes us and
how deep in the ladder are they", a **case timeline** on the contract answering "what did
we do and when" (the defensible audit trail the EU framing demands), and **manual
actions** for the human judgment the ladder can't encode.

## Scope

**In:** Billing → Delinquency page (replacing/absorbing the Overdue list), contract case
tab + timeline, manual actions (fee, overlock, release, notice, pause/resume, write-off),
notice mark-sent, seeder polish.
**Out:** any sending, playbook enrolment UI (S09), reporting/ageing exports (S17 reads
these same facts).

## Panel surface

### Billing → Delinquency

Header stat chips: open cases, Σ overdue by currency (grouped, never summed), overlocked
count, failed-autopay count (the S06 chip folds in here). Table of open cases: contact →
contract, days overdue (computed), amount overdue by type (rent vs fees, tooltip),
ladder progress — rendered as the policy's step dots with executed ones filled and the
next one dated ("overlock in 2 days") — paused badge, overlock glyph, last-payment date,
autopay state. Filters: site, days buckets (1–7 / 8–14 / 15–30 / 30+), paused,
overlocked. Row click → contract case tab. Cured cases live under a History tab
(append-only story per case).

The "next step in N days" prediction computes from anchor + policy — display-only,
never stored (invariant 5 discipline extends to predictions).

### Contract → Delinquency tab

Case header (opened, anchor, days, policy, pause state) + the **timeline**: every step —
ladder or manual — with date, actor (engine/employee), action, linked artefact (fee
charge amount, hold, notice with its mark-sent state, task), pause/resume entries,
cure line with trigger. Chronological, append-only, printable (window.print CSS is
enough — this listing is what goes to a lawyer if it ever must).

### Manual actions (case menu; all append `trigger=manual` steps with causer)

Assess fee (amount prefilled from policy math, editable, reason), Place/Release overlock
(per unit on multi-unit contracts), Record notice (type select) + **Mark sent**
(channel + date, the S02-04 endpoint finally used), Pause/Resume (reason required),
**Write off** — creates the `write_off` charge (reason required, Tier-3) which cures via
the 02 hook; confirm modal states it's excluded from revenue and irreversible-by-edit
(reversal idiom only).

i18n `billing.delinquency.*`; es: board → *Impagos*, write off → *Condonar deuda*,
mark sent → *Marcar como enviada*.

## Implementation notes

`useDelinquencyList` / `useDelinquencyCase`; board query is one aggregate endpoint
(`GET /api/delinquencies?filters…`) — no N+1 across cases (S01's matrix lesson); the
per-case computed numbers ride the same response. Ladder-dots component reads the policy
steps + executed set. Timeline reuses the activity-list rendering primitives where
sensible.

## Acceptance criteria

- [ ] Board renders seeded cases across all filters; chips accurate; currency grouped;
      bounded query count on 50 seeded cases.
- [ ] Ladder dots match executed steps; next-step prediction matches what the next run
      then does (fixture equality, the S05-04 idiom).
- [ ] Timeline shows the full `DelinquencySpineTest` story human-readably, prints sanely.
- [ ] Every manual action works, audits with causer, and appears on the timeline
      immediately; write-off cures end-to-end.
- [ ] Mark-sent persists channel/date on the notice row.
- [ ] `lint` + `typecheck`; `en/es/fr` complete.

## Tests required

API: `DelinquencyBoardTest::aggregate_endpoint_bounded_queries`,
`ManualActionsTest::each_action_audits_and_timelines`,
`PredictionTest::next_step_matches_engine` (the equality fixture).
Panel: manual script in the PR — board filters (1), ladder dots vs a known case (2),
full manual-action sweep on one case (3), write-off cure flow (4), print view (5),
multi-currency chips (6).
