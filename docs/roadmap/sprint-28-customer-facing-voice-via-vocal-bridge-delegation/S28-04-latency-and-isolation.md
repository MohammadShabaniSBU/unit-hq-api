# S28-04 — Latency, timeouts, and keeping voice off the shared queue

**Depends on:** S28-02
**Blocks:** nothing (but gates launch)
**Touches:** `unit-hq-api`

## Problem

`agents.turn_timeout_ms` is 60,000. Nobody waits 60 seconds on a phone call.

A voice turn is synchronous request/response: Vocal Bridge POSTs, waits, and
speaks what comes back. Inside that wait sits the whole runtime — nine
dispatch gates, one or more provider round trips, `GroundingGuard` with its
redraft loop, `ForbiddenClaimGuard`, `DisclosureGuard`. The redraft loop is
the one to watch: a blocked draft costs a second provider call, and on voice
that is the difference between a pause and a hang-up.

And `RespondWithAgent` holds one PHP worker per turn on the `ai` queue. Voice
doesn't go through that queue — it's a synchronous HTTP request — but it
shares a provider, a rate limit, and possibly a worker pool with everything
that does. One inbound email burst adds seconds to every phone turn.

## What to build

### A voice turn budget

Voice gets its own timeout, not the 60-second default. Start around 8
seconds: Vocal Bridge's per-query timeout is configurable from 5 to 120
seconds, so the ceiling is ours to choose and the caller's patience is the
real constraint. Put it in `config/agents.php` beside the existing timeout as
a per-channel override rather than a magic number at the call site.

When the budget is exceeded, return the fixed handoff sentence from S28-01
and log it. Do not return a partial answer.

### `late_response_behavior: store`, never `speak`

Vocal Bridge can store, speak, or reject a response that arrives after its
timeout. **Store.** A late answer spoken after the conversation has moved on
is worse than no answer — the caller hears a reply to a question they asked
two turns ago, in a voice that sounds confident. This is a Vocal Bridge
configuration value set in the Vocal Bridge dashboard, but the decision
belongs here, with the timeout
it pairs with.

### Redraft budget on voice

Cap the guard redraft loop at one attempt on voice, versus whatever it is
elsewhere. A second redraft doubles the provider cost inside a wait the
caller is sitting through. If one redraft doesn't produce a clean draft,
escalate rather than try again — an escalation at 6 seconds is a better
experience than a clean answer at 14.

### Queue and provider isolation

- Voice must not share a worker pool with `RespondWithAgent`'s `ai` queue
  drafting. Own connection, own pool, sized separately.
- Own token bucket in front of the provider so an email burst cannot consume
  the rate limit a live call depends on. Voice requests jump the queue; there
  is a person waiting.
- Size against measured turn wall-clock times arrival rate, not against a
  guess. The measurement below is the input.

### Measurement, as an acceptance criterion

Record, from the first real calls:

- p50, p95, p99 endpoint latency, split by whether a redraft occurred.
- Rate of turns that hit the budget.
- Rate of turns that escalate, and how many of those are account questions
  that verification would have answered. That last number is the business
  case for voice OTP in a later sprint, and nobody will be able to
  reconstruct it later.

Put the numbers in the PR and in `10-open-decisions.md` under the open
question on Tier 2. Tier 2 exists to buy sub-second latency at the cost of
every outbound guard; that trade is only worth discussing against a real p95.

## Acceptance criteria

- [ ] Per-channel turn timeout in config; voice uses it, other channels
      unchanged.
- [ ] Budget exceeded → fixed handoff sentence, logged, no partial answer.
- [ ] Redraft capped at one attempt on voice; a second block escalates.
- [ ] Voice does not execute on the `ai` queue connection; asserted in a test
      or documented in the deploy config, whichever is enforceable.
- [ ] Token bucket in front of the provider for voice, separate from batch.
- [ ] Latency and escalation-reason numbers recorded in the PR.

## Out of scope

- Filler audio while the caller waits. It is Vocal Bridge's side and it is a
  real mitigation, but it is configuration, not code — record the chosen
  value in the README launch gate.
- Caching the system prompt to shave latency. S27-01's reorder is the
  prerequisite and `laravel/ai` issue #119 may block it entirely; that
  investigation is already recorded as Undecided.
- Tier 2. Measure first.
