# S26-05 — Bound the context window

**Depends on:** nothing
**Blocks:** nothing
**Touches:** `unit-hq-api`
**Trace evidence:** usage rows trace-6 → trace-68 (3,140 → 18,214 input tokens)

## Problem

Input tokens grew ~6× over nine turns. The whole conversation cost about
$0.35 at the seeded model prices. At the volume a live SMS number receives,
that is the dominant cost of the feature, and it grows with conversation
length regardless of how trivial each turn is. Cause: every prior `role: tool`
message (five-site lists, eight-class availability lines, full proposal
summaries) is replayed into every subsequent model call.

S25-05 capped the trace envelope; it did not cap what is sent to the model.

## What to build

### `ContextWindow` (`App\Support\Ai\ContextWindow`)

Pure function: full message history → the message list sent to the driver.

| Rule | Behaviour |
|---|---|
| Recent verbatim | The last `config('agents.context.recent_turns')` (default 4) user/assistant pairs and their tool messages, unchanged |
| Older tool messages | Dropped. Their **facts and refs survive** because `FactBag` / `FactRegistry` are rebuilt from the append-only trace, not from model context |
| Older assistant/user text | Kept verbatim up to `config('agents.context.max_history_chars')` (default 6,000), oldest-first eviction |
| Rolling summary | When anything is evicted, a `system`-role line "Earlier in this conversation: …" built **deterministically** (no model call) from the conversation facts: site chosen, classes discussed, prices quoted (from `FactBag`), contact/deal ids created, stated needs. Template, not prose generation |
| Refs carried | The rolling summary ends with a consolidated `Refs:` line covering every licensed entity in the conversation, so S26-00's rule ("use only ids from a Refs line") stays true after eviction |

The runtime calls `ContextWindow::build()` immediately before
`ModelDriver::stream()`. Persistence is unchanged — `agent_conversation_messages`
still holds everything.

### Telemetry

`ai_usage_events.detail` (existing jsonb) gains `context: { messages_sent,
messages_evicted, summary_chars }`. Trace envelope `usage` row exposes the
same three numbers. No new columns.

### Cassettes

Cassettes hash the interpolated prompt; the rolling summary is part of the
message list, not the system prompt, so `CassetteKey` is unchanged. Recorded
responses were produced with the full history in context, so long fixtures
must be re-recorded. Only fixtures with more than `recent_turns` turns are
affected; list them in the PR.

## Acceptance criteria

- [ ] `ContextWindowTest`: a 12-turn history yields ≤ 4 verbatim pairs, no
      tool messages older than the window, one summary line with a `Refs:`
      line containing every entity from evicted tool results.
- [ ] Provenance test: after eviction, a call using an id that appeared only
      in an evicted tool result is still licensed (registry is trace-backed).
- [ ] Replay of the S26 fixture: input tokens on the final turn are below
      8,000; the conversation still converts.
- [ ] Usage row `detail.context` populated on every customer-facing turn.

## Out of scope

- Model-generated summaries (a second model call per turn is the cost we are
  trying to remove).
- Prompt caching flags per provider — record as a follow-up in `10`.
