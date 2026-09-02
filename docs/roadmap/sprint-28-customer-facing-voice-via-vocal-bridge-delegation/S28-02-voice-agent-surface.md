# S28-02 — The voice agent surface and number discipline

**Depends on:** S28-01
**Blocks:** S28-03, S28-04
**Touches:** `unit-hq-api`

## Problem

Two questions the sprint cannot avoid: which agent answers a voice call, and
what it is allowed to say out loud.

The first is easy to get wrong in the tidy direction. S27 spent a whole
sprint arguing that one customer-facing definition is right and that tool
exposure is decided by verification rather than by agent identity. Adding a
`VoiceAgentDefinition` re-opens that argument one sprint later.

The second is the sprint's actual product decision. A spoken price is not
retained. The caller cannot re-read it, cannot screenshot it, and will
misremember it — and if the foreground model paraphrases, the number that
was grounded in `pricing.quote` may not even be the number that leaves the
speaker.

## What to build

### One agent, not two

The concierge answers voice. `ChannelProfile::Voice` already exists at 600
characters and 2 sentences, and `channelBlock()` already puts it in the
prompt. That is the mechanism the codebase built for exactly this, and it is
the one to use.

What voice needs beyond the profile is a **narrower tool list on the binding,
not a narrower definition**. `agent_write_policies` is per
`(ai_agent_id, tool_key)` and cannot express "off on voice only", so this
needs a small addition: a per-binding tool denylist, or a `channel` column on
the write policy. Prefer the denylist on `agent_channel_bindings` — it is
additive, it reads as configuration rather than as a second policy system,
and it leaves the `agent_write_policies`-vs-RBAC question (still Undecided)
untouched.

Milestone-one voice denies everything except:

```
facility.find_sites, facility.site_info, facility.size_guide,
facility.availability, calendar.resolve, pricing.quote,
crm.create_contact, crm.create_deal, agent.escalate,
voice.send_quote_by_text
```

`billing.*`, `contract.summary` and `access.status` are already unreachable
at `channel_asserted`; denying them on the binding as well is belt and
braces, and it keeps the prompt honest, since the role paragraph for an
unverified principal doesn't mention them. `identity.request_code` and
`identity.verify_code` are denied explicitly — voice OTP is out of scope and
a half-built path is worse than none.

### `voice.send_quote_by_text`

The number-discipline rule, as a tool. The voice agent gets **no tool that
produces a figure it must speak**. A caller who asks for a price gets a
spoken range or a spoken "I'll text you the exact quote", and the figure goes
out through the existing text path where `pricing.quote`, `FactBag` and
`GroundingGuard` govern every digit.

- Sends via `SmsSender` with a transactional `SendContext` (invariant 38) to
  a `contact_channels` row, resolved server-side. Same rule as S27-04's
  verification code: the destination is never an argument.
- Requires a contact, so an anonymous caller must go through
  `crm.create_contact` first — which is the lead capture we want anyway.
- Returns a display string with **no figure in it**, so the model has nothing
  to read out.
- Site resolution through `ComposerIdentity::resolveSite()`, as S27-04
  established.

This is better product than speaking a price, not a safety compromise. A text
survives the call.

### Role paragraph

The concierge's role paragraph branches on verification (S27-00). Voice needs
one more sentence in the unverified branch, delivered through the channel
block rather than a fourth branch: state no figure aloud, offer to text it.
Keep it in `ChannelProfile::Voice`'s copy so the prompt-version hash stays
tied to the profile and not to a new branch.

### Fixtures

Voice fixtures with `channel: voice`, asserting:

- A price question produces `pricing.quote` **and** `voice.send_quote_by_text`,
  with no digit in the draft. This is invariant 73 at the fixture level.
- A balance question at `channel_asserted` produces `agent.escalate`, not a
  denial the model then narrates.
- Every draft is under 600 characters and two sentences.

## Acceptance criteria

- [ ] A voice conversation resolves the concierge definition; no new
      `AgentDefinition` class exists.
- [ ] Per-binding tool denylist exists, is enforced at dispatch, and denies
      with a distinct reason so it is visible in the trace.
- [ ] `voice.send_quote_by_text` sends to a server-resolved channel, returns
      no figure, and refuses without a contact.
- [ ] Fixture: price question → no digit in the spoken draft.
- [ ] Fixture: balance question → escalate.
- [ ] Fixture: every voice draft within the profile's ceiling.
- [ ] `identity.*` denied on voice bindings.

Introduces **invariant 73** and **D-AI-26** (S28-06).

## Out of scope

- A `VoiceAgentDefinition`. If the denylist proves insufficient, that is a
  finding to record, not a class to add mid-sprint.
- Speaking any figure, including availability counts and dates. "We have a
  few of that size left" is grounded in `Availability`; "we have three left"
  is a number and goes by text or not at all.
- Multi-site voice. One number, one site; establishing the site
  conversationally before quoting is deferred.
