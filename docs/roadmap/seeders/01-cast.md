# Demo-01 — The cast: persona journeys

## Context

Eighteen named people whose stories you tell in demos, hand-authored as day-scripted
journeys against the harness. Each persona exists to make one product story
undeniable on screen; together they cover every status the matrix demands at the
"show me" quality bar.

## Scope

**In:** the journey script format, the eighteen personas, their execution hooks into
02's loop. **Out:** the crowd (02), free-standing message texture (03 adds
conversation depth to several cast members).

## The journey format

`Database\Seeders\Demo\Journeys\{Name}.php` — a class with
`::script(): array<int dayOffset, callable(DemoWorld)>`; the loop (02) executes each
persona's due steps inside the matching tick. Days are offsets from simulation start;
helpers (`$this->signRemote(...)`, `$this->missPayments(2)`) wrap the service calls
so scripts read as stories. Every persona ends the simulation in a *deliberate*
state — the matrix rows below are assertions in 04.

## The eighteen (condensed spec — each gets its class + docblock story)

| Persona | Story it proves | End state |
|---|---|---|
| **Marcus Webb** | The mockup conversation: 8 m² tenant, 14 months, asks bigger → transfer +€40 | Active, transferred, SMS thread with the size question |
| Lucía Ferrer | Full delinquency ladder: fee d5, notice d8, suspension d8, overlock d12, **denied door event**, still owing | Open case 15-30 bucket, overlocked, timeline complete |
| Tom Bradley | Promise-keeper: called (wrap-up `payment_promised`), paid day 4 | Cured history; feeds promise-kept rate |
| Amara Okafor | Long-stay free-time walk-in (signed ~3w before seed-end) | Active; still in €0 window on rent roll |
| Jean-Luc Perrin | Awaiting-declined: envelope declined with reason | Awaiting, declined attention chip |
| Sofía Marín | Awaiting-expiring: sent 12 days ago, 14-day expiry | The amber ≤3d row |
| Derek Hoyle | Non-payment vacate: 60+ bucket → write-off → ended | The involuntary line + write-off cure |
| Pilar Santos | WhatsApp dance: template outside window → reply opens window → free-form exchange | Open window at seed-end (script the last inbound near end-date) |
| Hannah Cole | Autopay-failing: card added, then `insufficient_funds` twice, manual retry pending | The amber autopay chip + failed attempts |
| Rafa Núñez | Payment-link lifecycle: link sent in thread, paid via synthetic webhook | Paid request, the S11 flagship visible |
| Ingrid Weiss | Notice-given, mid-notice, move-out scheduled next week | Notice tab row, stop-line visible in runs |
| Omar Haddad | Pending: signed walk-in, move-in 10 days after seed-end | Pending tab; activation-pending |
| Grace Lin | Deal in negotiation, offer viewed not accepted, lead-chase step 3 of 4 | Open funnel mid-stage + live enrolment |
| Bea Torres | Suppression story: hard bounce → suppressed email, SMS fallback thread | Suppressed badge, sequence skipped-with-reason |
| Viktor Palenik | Cancelled contract (never moved in) + lost deal | The cancelled + lost rows |
| Nadia Rahal | 20% tracking discount; applied rate change + one scheduled 2 months out | Rate-change recompute visible in history |
| The Kellys | Two units, one contact; one unit vacated last month with deposit deduction | Multi-contract panel + settlement with deduction |
| Front-desk misc | Voicemail persona, triage stranger ×2, wrong-number call | Triage queue + call textures |

## Acceptance criteria

- [ ] Every persona class runs green in isolation on a bare stage (per-persona smoke
      — journeys are independently executable for debugging).
- [ ] Each end-state row asserted (04 owns the full matrix; this task ships a
      per-persona assertion method the matrix reuses).
- [ ] The stories read: a new developer can open `MarcusWebb.php` and follow it
      without the transcript of this project in hand (docblock narrative required).

## Tests required

`PersonaSmokeTest` (parameterised over the eighteen), per-persona `assertEndState()`
methods consumed by 04.
