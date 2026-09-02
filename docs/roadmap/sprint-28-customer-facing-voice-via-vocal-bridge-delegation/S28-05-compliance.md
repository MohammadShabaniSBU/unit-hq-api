# S28-05 — Disclosure, recording consent, residency

**Depends on:** nothing — start day one
**Blocks:** launch, not merge
**Touches:** `unit-hq-api`, configuration, legal

## Problem

Voice changes the compliance picture in three ways, and all three need
someone outside engineering to sign off. Starting this in the final week is
how a built feature sits unlaunched.

**Disclosure.** `DisclosureSentence::isFirstCustomerTurn` puts the AI
disclosure in our first delegated reply. On voice that is too late: the
foreground agent speaks first, greets the caller, and may exchange several
turns before it decides to delegate anything. A caller can have a
conversation with an AI and never be told.

**Recording.** A phone call is a recording question in a way that a chat
thread is not. Spain and most of the EU require notice; some jurisdictions
require consent. Vocal Bridge holds the audio, so this is also a processor
question.

**Residency.** Audio is biometric-adjacent personal data with a heavier
posture than text. Where the audio is processed, where the transcript lives,
how long either is retained, and under what processor agreement are all
questions that need answers before a real caller reaches the number. The
provider and EU residency question is already open in
`10-open-decisions.md`; voice makes it blocking rather than pending.

## What to build

### The disclosure line

Fixed, non-configurable, spoken before anything else. It belongs in the
foreground agent's greeting, set in the Vocal Bridge dashboard, because that
agent speaks first — our
`DisclosureSentence` stays as the backstop on the first delegated reply, and
having both is correct rather than redundant.

Non-configurable is the point. An operator who can edit the disclosure is an
operator who can remove it, and Art. 50 obligations do not have an operator
toggle. Write the line once, per locale, in the codebase; the bridge
configuration references it rather than restating it.

The exact wording needs the legal read. Draft it, don't finalise it.

### Recording consent

Decide and record three things:

1. Whether calls are recorded at all in milestone one. **Recommend not.** The
   transcript is in `agent_conversation_messages` already, the audio adds a
   consent obligation and a retention obligation, and nothing in the DoD
   needs it. If it is not recorded, say so in the greeting.
2. If recorded: notice or consent, per jurisdiction, and what happens when a
   caller declines.
3. Retention and deletion, and how a `contacts:redact` run reaches it. Note
   that AR-03 already covers a set of agent tables that redaction does not
   touch; voice adds `voice_sessions` and, if recorded, audio held by a
   processor. **Do not launch voice into an unclosed AR-03** — a redaction
   defect that spans a third-party processor is materially worse than one
   confined to our own tables.

### Residency and processing

Establish, in writing, before launch:

- Where Vocal Bridge processes audio and where any transcript rests.
- Which model provider sits behind the foreground agent, and where.
- The processor agreement covering both.
- Retention defaults on their side, and whether they are configurable.

If any answer is unsatisfactory, that is a launch blocker, not a caveat. It
is cheaper to find out now than after a number is live.

### Aircall

No evidence has been found of first-party bidirectional media streaming on
Aircall. Three questions for their solutions team, worth asking during this
sprint even though the answer changes nothing here: real-time media
streaming; SIPREC or media forking; bring-your-own SIP trunk. Plan for no.

Ride the same email with a Vocal Bridge question: what are their egress IP
ranges? If they publish them, an IP allowlist is a stronger control than the
shared-secret header alone. Not a code task this sprint — send the question;
S28-07 records the answer or non-answer.

The pragmatic split stands and should be recorded as the decision: Vocal
Bridge numbers for AI-answered calls, transfer to Aircall for humans, Aircall
integrated at CRM level through webhooks. Record it in `10-open-decisions.md`
whether or not the answers arrive.

## Acceptance criteria

- [ ] Disclosure line drafted per locale, legally reviewed, non-configurable,
      and spoken first on a real call — verified by listening.
- [ ] Recording decision made and written down, with the greeting matching it.
- [ ] Residency and processor questions answered in writing and recorded.
- [ ] AR-03's status relative to voice stated explicitly: either closed, or a
      documented decision to launch a channel it does not cover.
- [ ] Aircall questions asked; answers or non-answers recorded.
- [ ] Vocal Bridge egress IP ranges asked on the same email; answer or
      non-answer recorded (S28-07). Do not implement an allowlist until they reply.
- [ ] `voice_sessions` and any audio reference added to AR-03's table list.

## Out of scope

- Closing AR-03 itself. That is its own piece of work and has been outstanding
  since S26. This task decides whether voice may launch ahead of it, which is
  a different question and needs an explicit answer rather than silence.
- Consent capture UI. There is no screen; it is a spoken exchange.
