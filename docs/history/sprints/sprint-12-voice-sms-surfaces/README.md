# Sprint 12 — Voice & SMS Surfaces

## Goal

Calls become **actionable**: click-to-call from everywhere a phone number implies an
action (inbox call-back, contact page, delinquency board, "call the tenant" tasks), an
incoming-call banner that names the tenant before pickup, and call wrap-up (disposition,
notes, recording playback) that turns telephony into CRM data. Plus the small SMS
entry-point polish S11 left on the table.

This sprint is deliberately lighter (~4 days): S10 built two-way SMS completely and
Aircall's receive side; S11 built the reply surfaces. What's left is the dial direction
and the operator ergonomics around it.

## How Aircall dialing works (constrains everything)

Aircall click-to-call does not place a WebRTC call from our page. The API instructs an
**Aircall user's** phone/app to dial: our button → their phone rings → they pick up →
Aircall dials the tenant. Consequences: (1) every employee who dials must be **mapped to
an Aircall user**; (2) "call" buttons are enabled per-employee by mapping + availability;
(3) the resulting call arrives back through the S10 webhook path like any other — our
outbound click and the eventual call message meet via the call id. Verify endpoint
shapes against current Aircall API docs at implementation (the standing third-party
rule).

## Prerequisite (carried, final mention)

`LeadKindTest` — still absent. The exit semantics your lead-chase UI runs on are
untested. One session.

## Exit criteria

- [x] A mapped operator clicks Call on a delinquent tenant; their Aircall device rings,
      the call connects, and the call message lands on the thread linked to the click
      (correlation, not coincidence).
- [ ] Incoming call from a known contact shows a banner with name + context link within
      the polling latency; unknown numbers link to triage-style resolution.
- [x] Every call message can carry a disposition + note; recordings play in-place from
      the provider URL; voicemails are visually distinct and unread until handled.
- [x] The S11 disabled call-back affordance is live for mapped employees, honestly
      disabled-with-reason otherwise.
- [ ] SMS composable from the contact page and the delinquency board without detouring
      through the inbox.

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Aircall users & dialing](./00-aircall-dialing.md) | 1 day |
| 01 | [Call surfaces & incoming banner](./01-call-surfaces.md) | 1 day |
| 02 | [Call wrap-up: disposition, notes, recordings](./02-call-wrapup.md) | 1 day |
| 03 | [SMS entry points & docs](./03-sms-entry-points.md) | 0.5 day |

## Risks

**Latency honesty on the banner.** The incoming-call banner rides the S11 poller (20s
worst-case) — a ringing call lasts ~25s, so it will sometimes appear post-pickup as
"on a call with…" rather than pre-pickup. Ship it honest (the banner states the call
phase from the webhook data), record WebSockets as the fix in the standing realtime
entry. Do **not** build a parallel fast-poll just for this.

**Dial ≠ call.** The dial API accepting is not a call happening (agent may not pick
up their own device). The click records an *intent* row; the call message, when the
webhook delivers it, links back by call id. Never synthesize a call message from the
click — the S06 no-optimism rule, telephony edition.

**Recording URLs expire on some Aircall plans.** Play via fresh fetch-through (our API
proxies/refreshes the URL server-side at click time), never persist a signed URL into
the message body. Mirroring media stays deferred.
