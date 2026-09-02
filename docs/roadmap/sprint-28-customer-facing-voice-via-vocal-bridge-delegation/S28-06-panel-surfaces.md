# S28-06 — Panel: a call an operator can see

**Depends on:** S28-01
**Blocks:** nothing
**Touches:** `unit-hq-panel`

## Problem

Voice produces the least visible conversations in the product. There is no
thread in the Inbox, no draft to approve, no message an operator can read
back. A caller rings, an AI answers, something is said, the call ends. If the
customer later says "your system told me €89 a month", an operator needs to
be able to find out what happened.

S27-05 established the constraint this task works under: there is no agent
conversation detail page. `DemoChatTracePane.vue` is the only surface that
renders a trace, and it lives in the demo. The panel also has **no test
tooling at all** — `package.json` carries `lint`, `typecheck`, `build` and
nothing else — so everything here rests on typecheck and manual verification.

## What to build

Keep it to what answers the question above.

### Voice sessions list

A page listing `voice_sessions`: when, caller number, matched contact if any,
site, duration, whether it transferred. Filterable by date and
site. This is the entry point, and on its own it is most of the value.

**Disposition is unknown.** Vocal Bridge does not POST a session-end
disposition to the AI-agent endpoint (confirmed S28-03: `--transfer-enabled`
/ `--transfer-destination` and `vb logs` exist; no inbound webhook). Do not
add `voice_sessions.disposition` until something writes it. The list and
detail views render “unknown — Vocal Bridge does not report transfer
disposition” with no column. `ended_at` stays null unless a later inbound
event appears.

### Session detail

The conversation's turns, rendered as a transcript: what the caller asked as
Vocal Bridge delegated it, what we answered. Plus the trace for each turn —
tools called, guard verdicts, denials — reusing the trace components rather
than writing new ones. `agentTrace.ts` and the `DemoChatTracePane` rendering
are the parts to extract and share, and extracting them is worth doing here
rather than copying, because a third copy of trace rendering will diverge.

Two things this view must make obvious:

- **Verification level**, and that it is `channel_asserted` at best. An
  operator looking at a call where a caller asked about their balance should
  see immediately why the agent didn't answer.
- **The gap between what we returned and what was spoken.** We store the
  draft; the caller heard whatever the foreground agent said. Under verbatim
  plus external TTS those should match, but the panel should not imply we
  know that. Label the stored text as what the agent returned, not as what
  the caller heard. If the launch gate's listening test shows they can
  diverge, that
  label is the only honest thing in the view.

### Bindings

The read-only bindings table from S27-05 gains voice rows. Voice bindings
carry the per-binding tool denylist from S28-02 — show it, since a denied
tool is why the agent transferred and an operator with no visibility into it
will file a bug.

### Not in the Inbox

Do not put voice conversations in the Inbox. The Inbox is a work queue of
things needing a reply, and a finished call needs none. A transferred call
that produced a lead already creates a Deal through `crm.create_deal`; that
is where the follow-up work belongs.

## Acceptance criteria

- [ ] Sessions list renders, filters by date and site, and is permission-gated.
- [ ] Session detail shows the transcript and per-turn trace.
- [ ] Verification level displayed with its meaning, not as a raw enum.
- [ ] Stored text labelled as what the agent returned, never as what the
      caller heard.
- [ ] Trace rendering extracted and shared rather than copied a third time.
- [ ] Bindings table shows voice rows and their denylist.
- [ ] i18n for every string in `en`, `es`, `fr`; `Array<T>`; `useApi()`.
- [ ] `bun run lint` and `bun run typecheck` clean.

## Out of scope

- Audio playback. Milestone one does not record (S28-05).
- Live call monitoring or barge-in.
- A general agent conversation detail page. Still a gap, still recorded in
  S27-06's known gaps; this task does not close it and should not pretend to
  by building a voice-only version and calling the surface done.
