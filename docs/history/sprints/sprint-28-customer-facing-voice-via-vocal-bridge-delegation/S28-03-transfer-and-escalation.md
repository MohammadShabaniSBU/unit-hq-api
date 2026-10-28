# S28-03 — Transfer: what escalation means when someone is on the line

**Depends on:** S28-02
**Blocks:** nothing
**Touches:** `unit-hq-api`

## Problem

`agent.escalate` writes an `agent_handoffs` row and stops. On email and SMS
that is correct and sufficient — the thread lands in the Inbox and a human
picks it up when they can. On a phone call it is a person listening to
silence.

Voice is also the channel where escalation is most frequent, because it is
the channel where verification is unreachable. Every account question is a
transfer. If transfer is bad, the product is bad.

## What to build

### Transfer as a response signal, not a tool

Do not add a `voice.transfer` tool. The model already has `agent.escalate`
and already knows when to reach for it; adding a second, channel-specific way
to end a conversation gives it a choice it does not need and a chance to get
wrong. Instead: when a voice turn produces an `agent_handoffs` row, the
S28-01 response carries a transfer signal alongside the spoken text.

The text still matters. The caller should hear a sentence that sets up the
transfer — the reason, briefly, and that they're being put through — before
the line moves. That sentence goes through the same guards as any other
draft.

### Approved destinations only

Vocal Bridge gates transfer behind `--transfer-enabled` and a set of
`--transfer-destination` values. Keep that gate and mirror it our side: the
response names a destination **key**, not a number, and the mapping from key
to number lives in the Vocal Bridge dashboard (README launch gate). A model
that can emit
an arbitrary destination string is a model that can dial anywhere.

Milestone one needs two keys at most: the site's main line, and an
out-of-hours voicemail. `HandoffReason` already distinguishes cases; map
reasons to destination keys in configuration rather than in the model's
output.

### Out of hours

`agent_channel_bindings.outside_hours` stays `inbox` | `answer` — the enum
is not extended. Hours are `SiteClock::withinWindow()` against the company
send window in the site's timezone (there is no `SiteClock::now()`).

**Decision:** treat `inbox` as "take a message" on voice.

- `inbox` + outside the send window → do not run the runtime. Write
  `agent_handoffs` (`reason=out_of_hours`, `trigger_source=rule`), speak
  the canned voicemail sentence, transfer to the `voicemail` key.
- `answer` + outside hours → run the agent. Any transfer remaps to
  `voicemail` (no human on the main line).
- Inside hours → destination from the `HandoffReason` map (almost always
  `main_line`).

An unmapped reason with `main_line` still approved transfers to
`main_line` and logs `ai.voice.transfer_unmapped`. True fail-closed (no
transfer, apology) is only when `approved_destinations` is empty or
missing.

This is also on `OutsideHoursPolicy` itself — the next reader of that
enum will not have this file.

### Handoff records

The `agent_handoffs` row gains nothing structurally, but the trace must show
whether the transfer actually happened. Vocal Bridge knows; we don't, unless
it tells us. If its session-end webhook or event stream reports the
disposition, record it on `voice_sessions.ended_at` plus a disposition
column. If it doesn't, record that we cannot know — an unmeasurable transfer
rate is worth flagging now rather than discovering when someone asks for the
number.

## Acceptance criteria

- [ ] A voice turn producing a handoff returns a transfer signal with a
      destination **key**, never a number.
- [ ] The spoken sentence accompanying a transfer passes the guards.
- [ ] An unmapped reason with `main_line` approved transfers to
      `main_line` and logs `ai.voice.transfer_unmapped`. Empty
      `approved_destinations` fails closed: no transfer, apology, no
      destination field.
- [ ] Out-of-hours behaviour on voice is defined, implemented, and tested
      against `SiteClock` in the site's timezone.
- [ ] Transfer disposition is not a column. Vocal Bridge does not report
      it to us; the gap is written into S28-06.
- [ ] No new tool key exists for transfer.

## Out of scope

- Warm transfer with context handed to the human, and whisper/announce.
  Milestone one is a cold transfer.
- Aircall. The pragmatic split — a Vocal Bridge number for AI-answered
  calls, transfer to Aircall for humans, Aircall integrated at CRM level via
  webhooks — is recorded in S28-06 and needs no code here.
- Callback scheduling as an alternative to transfer.
