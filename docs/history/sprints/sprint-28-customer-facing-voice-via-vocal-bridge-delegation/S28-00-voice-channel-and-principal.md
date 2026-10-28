# S28-00 — Open the voice channel, and get the principal right

**Depends on:** S27-01 recording pass
**Blocks:** S28-01, S28-02
**Touches:** `unit-hq-api`

## Problem

Two lines keep voice closed, and behind them sits a defect that only appears
once they're lifted.

`AgentChannel::bindable()` excludes `Voice`, and
`AgentConversationController::store()` carries
`Rule::enum(AgentChannel::class)->except([AgentChannel::Voice])`. Lifting
both is trivial.

The defect is `deriveAudience()`. It returns `Customer` when a contact id is
present or the origin is `demo`, and `Internal` otherwise. A voice call from
a number we don't recognise has no contact id and isn't `demo`, so it would
open an **employee-audience** conversation — the copilot's audience, with the
copilot's principal semantics — for an anonymous member of the public. That
is the single most dangerous line in this sprint.

## What to build

### Channel and origin

- Add `Voice` to `AgentChannel::bindable()`.
- Drop the `->except([AgentChannel::Voice])` clause in `store()`.
- Add `Voice` to `AgentOrigin`. Do not reuse `Webchat`: origin drives
  `deriveAudience()`, metric exclusion (invariant 59), and the trace, and a
  voice turn that reports as webchat is untraceable.

### `deriveAudience()`

Voice belongs in the `Customer` branch unconditionally, alongside `demo`.
Restate the method as an allowlist of origins that may be `Internal` rather
than a fallthrough, so the next channel added doesn't inherit the same bug.
This is the change that needs a comment more than any other in the sprint.

### `resolveVerification()` for voice

```
caller number matches a contact_channels row → ChannelAsserted
otherwise                                    → Anonymous
```

Never `Verified`. Caller ID is spoofable, so a match means "the call arrived
from a number we have on file", which is exactly `channel_asserted`'s
existing meaning and no more. S27-04's OTP path is not reachable from voice
in this sprint; see invariant 74 (S28-06).

### Caller-ID matching

Reuse the inbound normaliser rather than writing a second one — a number
matched one way at the door and another way in the Inbox produces two
contacts for one caller. Match on normalised E.164 against
`contact_channels`. On multiple matches, treat it as **no match**: a shared
number is not an identification, and silently picking the first is how one
tenant hears about another. Log an `ai.voice.caller_ambiguous` system event
so the case is visible rather than invisible.

### Binding

Voice binds like any other channel through `agent_channel_bindings`. Seed
nothing — the first number is configured deliberately in the Vocal Bridge
dashboard (README launch gate), not by a
seeder. `BindingAudience` semantics are unchanged: `known_contacts` on voice
means an unmatched caller goes to a human, which is a defensible starting
posture and probably the one to launch with.

## Acceptance criteria

- [ ] `POST /api/agent-conversations` accepts `channel=voice` and no longer 422s.
- [ ] `AgentChannel::bindable()` includes `Voice`; a voice binding can be created.
- [ ] `AgentOrigin::Voice` exists and appears in the trace.
- [ ] Feature test: a voice conversation with **no** contact id derives
      `AgentAudience::Customer`, not `Internal`. This is the regression test
      that matters most in the sprint.
- [ ] Feature test: caller number matching one `contact_channels` row →
      `ChannelAsserted` with that contact.
- [ ] Feature test: caller number matching two rows → `Anonymous`, no contact
      stamped, one `ai.voice.caller_ambiguous` event.
- [ ] Feature test: no voice path can produce `VerificationLevel::Verified`,
      including after a successful `identity.verify_code` — assert the tool is
      not in the voice tool list (S28-02) and that the level stays capped.
- [ ] Withheld / anonymous caller ID → `Anonymous`, conversation still opens.

Introduces **invariant 74** (S28-06).

## Out of scope

- The endpoint that creates these conversations — S28-01.
- Which tools the voice agent may call — S28-02.
- Any change to `PrincipalPromotion`. Voice adds no promotion path; the
  `crm.create_contact` path may still fire on voice and take an anonymous
  caller to `channel_asserted`, which is correct and unchanged.
