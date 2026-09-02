# S28-07 — Invariants, decisions, docs

**Depends on:** S28-00 … S28-06
**Blocks:** nothing
**Touches:** `unit-hq-api/docs`

Lands last, changes no behaviour, and records only what shipped. Where a
task's acceptance criterion was not met, this file says so rather than
describing the intent.

## Invariants (`09-conventions-and-invariants.md`)

Next free number after S27 is **73**.

> **73. What a caller hears is a draft the guards passed, spoken verbatim.**
> The voice bridge runs in verbatim mode with external TTS. A paraphrase of an
> agent draft is unvalidated text spoken in the company's name: `GroundingGuard`,
> `ForbiddenClaimGuard` and `DisclosureGuard` validated the draft, not a
> restatement of it. The voice agent additionally holds no tool that produces
> a figure it must speak — prices go out through `voice.send_quote_by_text`
> on the text path, where `pricing.quote`, `FactBag` and `GroundingGuard`
> govern every digit.

**Write this only if the launch gate's listening test confirms it.** If
verbatim turns
out to be best-effort in the deployed configuration, the invariant is false as
stated and must be written as the weaker true thing plus an open decision. An
invariant that isn't enforced is worse than no invariant.

> **74. Caller ID never produces `verified`.** A matched calling number means
> the call arrived from a number on file — `channel_asserted`, and no more.
> Caller ID is spoofable. `verified` is reachable only through a
> `contact_verifications` challenge (invariant 72), and voice holds neither
> identity tool. Account questions on a call are transfers.

Amend **invariant 59**'s surrounding prose if voice is excluded from any
metric, and check whether **invariant 42**'s public-route list needs the
bridge route named.

## Decisions (`10-open-decisions.md`)

Under Decided:

> **D-AI-24 — Customer voice is a delegation relay, not speech-to-speech.**
> Vocal Bridge hosts the call; when its foreground agent judges a question
> domain-specific it POSTs to a hosted endpoint and speaks the reply. Every
> guard, gate and trace stays in `AgentRuntime` unchanged. Tier 2 (realtime
> speech-to-speech with tools called directly) buys sub-second latency at the
> cost of every outbound guard and is not built; the p95 measured in S28-04 is
> the input to reconsidering it.

> **D-AI-25 — The bridge authenticates with a path token plus a body HMAC.**
> Not a Sanctum PAT: a personal access token in a third-party dashboard is an
> employee identity with the whole API behind it. One token per number so a
> leak is revocable in isolation, HMAC over the raw body so a leaked URL in a
> log is not sufficient, and an independent rate limit because this is the
> only unauthenticated path reaching the agent runtime.

> **D-AI-26 — The voice agent speaks no figure it produced.** Prices,
> discounts, availability counts, dates, balances, invoice figures, unit
> numbers and access codes all go out by text or not at all. A spoken price is
> not retained and cannot be re-read; a text survives the call. This is
> better product as well as the safer construction.

Also record the **Aircall** position from S28-05 — the questions asked, the
answers or non-answers, and the pragmatic split — and update the open
question on provider residency with whatever S28-05 established.

## `14-ai-agents.md`

| Section | Change |
|---|---|
| Channels | Voice becomes bindable; describe the relay shape and where each half runs |
| Principal and verification | Voice caps at `channel_asserted`; state why caller ID is not identity |
| Tool catalogue | Add `voice.send_quote_by_text`; note the per-binding denylist and which keys voice denies |
| Bindings | Per-binding tool denylist; voice out-of-hours behaviour |
| Deliberately not built | Remove customer-facing voice. Add Tier 2, voice OTP, multi-site voice, outbound calling |
| Known gaps | Whatever S28-03 found about transfer disposition visibility; the panel testing gap from S27-06 still stands |
| Vocal Bridge | The existing copilot section gains the customer-facing counterpart, with the two integration modes clearly distinguished — client data channel for copilot, hosted endpoint for customers |

## Elsewhere

- `00-overview.md` — voice joins the channel list.
- `06-communications.md` — voice is not a `messages` channel; say so explicitly,
  since `voice.send_quote_by_text` does write one and the distinction will
  confuse someone.
- `08-activity-logging.md` — add the events this sprint introduced:
  `ai.voice.caller_ambiguous`, `ai.voice.bridge_auth_failed`, and anything
  S28-03 added for transfer disposition.
- `AGENTS.md` — one line: a voice caller is never verified.
- `docs/roadmap/README.md` — S28 in the Phase H block, with an exit criterion
  that states what actually shipped.

## Acceptance criteria

- [ ] Invariants 73 and 74 written; 73 only in the form the launch gate's
      listening
      test supports.
- [ ] D-AI-24, 25, 26 recorded; Aircall and residency positions written down.
- [ ] `14-ai-agents.md` sections above rewritten; the two Vocal Bridge
      integration modes clearly distinguished.
- [ ] Every event key in `08-activity-logging.md`.
- [ ] Roadmap index updated, including S27's outstanding recording pass if it
      is still outstanding.
- [ ] Grep check: no doc outside `roadmap/sprint-2[2-7]-*` describes
      customer-facing voice as not built.
