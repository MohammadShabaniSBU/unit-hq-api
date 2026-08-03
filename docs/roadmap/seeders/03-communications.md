# SEED-03 — Communications population

## Context

The hundreds of messages — but woven **into the simulation timeline**, not bolted on
after it. A message store populated in one pass at the end would have every
`created_at` on seed day and no causal relationship to the tenancies; instead, the
day loop (02) gains comms behaviours, so threads grow *as the world happens*: the
offer email precedes the acceptance, the dunning SMS lands on day 8 of the case that
opened on day 1, the reply arrives the afternoon after the send.

## Scope

**In:** day-loop comms behaviours, inbound reply generation per archetype, content
library, WhatsApp window choreography, call/wrap-up population, seed-end inbox
staging (unread, triage, suppression), delivery-state distribution.
**Out:** nothing new in the app — every message flows through the S10 senders or the
inbound/delivery injectors (00); a message created any other way fails task 04's
grep.

## Behaviours woven into the loop

- **Pipeline comms (automatic):** offer sends, playbook steps (debt + lead chase run
  live during simulation — their sends *are* most of the outbound volume), payment
  links inserted into replies for two cast personas. The playbook-sourced messages
  get delivery outcomes via the delivery injector the following simulated hour —
  the run logs show delivered/bounced backfill (the S10 loop, demonstrated).
- **Manual-style traffic:** per-archetype behaviour table — e.g. *negotiator*
  contacts reply to offer emails twice before accepting; *quiet leads* never reply
  (lead-chase runs its full sequence and exits by loss); *chatty tenants* send an
  inbound email/SMS every few weeks (threaded onto existing threads by the real
  resolver — subject-reuse exercised); *the promise-keeper* answers the day-8 call.
- **Delivery states:** injector distributes the lattice honestly — most delivered,
  ~8% opened→clicked (email), 2 hard bounces and 1 complaint (→ real suppressions
  via the S10 writers — the 3 suppressed contacts are *earned*, not inserted), a
  few `failed` with provider errors.
- **Inbound content library:** ~40 short bodies per channel/intent (es-weighted —
  the demo audience reads Spanish), token-varied so no two threads read identical;
  attachments on 3 inbound emails (a DNI scan placeholder PDF, a photo) exercising
  the attachment path + one oversize stub.
- **WhatsApp choreography (cast):** one persona's full dance — template sent
  (window closed) → tenant replies (window opens) → free-form exchange → window
  lapses → next contact is template again. Seed-end states staged by *timing the
  last inbound*: one window open (inbound 3h before end, countdown live in the
  demo), one closed-today, rest closed.
- **Calls:** the Aircall injector fabricates lifecycles for ~25 calls across the
  months — inbound answered (wrap-ups: 4 `payment_promised` feeding the S16
  collections report, mixed others), 3 missed (unread), 2 voicemails, 1 unknown
  number left unresolved (→ triage), plus outbound calls correlated to seeded
  intents from the delinquency cast journeys.

## Seed-end inbox staging (what the demo opens on)

The final simulated days are tuned so the inbox lands looking *alive*, matching the
original mockup: **7 unread threads** (the badge number from the first screenshot —
a deliberate wink), mixed channels among them, 2 assigned to the demo operator / 3
unassigned / rest across staff, **4 triage rows** (the unknown caller + 2 unmatched
emails + 1 unmatched SMS), the open-window WhatsApp thread near the top, one thread
whose last message shows `bounced` red, and the suppressed-contact thread showing
the composer warning. Nothing about this staging is special-cased — it's the last
days' event timing chosen carefully.

## Acceptance criteria

- [ ] 450–600 messages, distribution per the README matrix; ≥40 threads contain
      inbound; every `created_at` inside the simulation window (no seed-day pileup —
      assert the histogram spread).
- [ ] All delivery states present incl. the earned suppressions; playbook messages
      show backfilled outcomes in run logs.
- [ ] WhatsApp end-states exactly as staged (open/closing/closed) — the window test
      reads them live.
- [ ] Call population: wrap-up dispositions present incl. the 4 promises; triage = 4;
      unread = 7 on the fresh seed.
- [ ] Grep/DB assert: zero messages without a sender-or-injector provenance.

