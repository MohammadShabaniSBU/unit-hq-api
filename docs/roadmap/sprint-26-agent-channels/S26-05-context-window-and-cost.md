# S26-05 — Bound the context window

**Depends on:** S26-02 (stated-needs segment reads deal columns `crm.create_deal` writes)
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
Called from `AgentRuntime::streamMetered()` immediately before
`ModelDriver::stream()`. Persistence is unchanged —
`agent_conversation_messages` still holds everything. `build()` is
idempotent (strips a prior summary tagged with a reserved key before
recomputing) so the guard-redraft loop can call the driver again on an
already-trimmed list.

| Rule | Behaviour |
|---|---|
| Recent verbatim | The last `config('agents.context.recent_turns')` (default 4) user/assistant pairs and their tool messages, unchanged. The window opens at the Nth-from-last `role: user` message, so the current turn (including tool messages accrued this turn and the redraft `system` retry line) is inside the window by construction |
| Older tool messages | Dropped. Their **facts and refs survive** because `FactBag` / `FactRegistry` are rebuilt from the append-only trace, not from model context |
| Orphaned `tool_calls` | Dropping a tool message also strips `tool_calls` from its parent assistant message, and drops that assistant message if `content` is then empty. An orphaned `tool_use` with no matching `tool_result` is a provider 400 |
| Older assistant/user text | Kept verbatim up to `config('agents.context.max_history_chars')` (default 6,000), oldest-first. When the budget forces eviction, **assistant turns go before user turns** so the customer's own words survive longest |
| Rolling summary | When anything is evicted, a `system`-role line built **deterministically** (no model call). Nothing is paraphrased from the transcript. Segments: site / classes / created ids from `FactRegistry`; stated needs from the deal row (`expected_move_in`, stay length/period, `desired_size`, `desired_unit_class_id` label, contact first name, deal site) — omitted when the conversation has no deal ref; prices quoted from the `display` of evicted `ok` invocations whose tool returns `retainInSummary() === true` (default `false`; `pricing.quote`, `sales.propose_offer`, `sales.create_offer` opt in), one line each, most recent per `(tool_key, unit_class_id)` |
| Refs carried | The rolling summary ends with a consolidated `Refs:` line via `RefsRenderer::render()` over the full registry, so S26-00's rule ("use only ids from a Refs line") stays true after eviction |

`agent_conversations` has no `deal_id`. The deal is resolved from
`FactRegistry::ids(EntityType::Deal)` — licensed by `EntityRef::deal()` on
`crm.create_deal`.

### Telemetry

Nullable json column `ai_usage_events.context` holds
`{ messages_sent, messages_evicted, summary_chars, estimated_tokens }`,
written by the runtime in the same insert as the usage row
(`AiUsageEvent::reserve()`). `raw_usage` stays the untouched provider
payload. Trace envelope `usage` row exposes `messages_sent`,
`messages_evicted`, `summary_chars`.

(`estimated_tokens` is a deterministic character-based estimate so the
offline cassette harness can assert context size. CassetteDriver does not
record real `prompt_tokens`.)

### Cassettes

Cassettes hash the interpolated prompt; the rolling summary is part of the
message list, not the system prompt, so `CassetteKey` is unchanged. Recorded
responses were produced with the full history in context, so long fixtures
must be re-recorded. Only fixtures with more than `recent_turns` turns are
affected:

- `tests/Fixtures/agents/sales/sales_madrid_boxes_to_offer.yaml` (10 turns)
- `tests/Fixtures/agents/sales/summaries-only-six-turn.yaml` (6)
- `tests/Fixtures/agents/sales/reservation-stale-claim.yaml` (5)

Re-record with `php artisan agent:replay --live --record`. List them in the PR.

## Acceptance criteria

- [ ] `ContextWindowTest`: a 12-turn history yields ≤ 4 verbatim pairs, no
      tool messages older than the window, no orphaned `tool_calls` on
      evicted assistants, one summary line with a `Refs:` line containing
      every entity from evicted tool results. `build()` is idempotent.
- [ ] Provenance test: after eviction, a call using an id that appeared only
      in an evicted tool result is still licensed (registry is trace-backed).
- [ ] Offline: `expect_context_estimated_tokens_max: 8000` on the final turn
      of `sales_madrid_boxes_to_offer.yaml` (character-based estimate).
      Live: input tokens on the final turn are below 8,000; the conversation
      still converts.
- [ ] Usage row `context` populated on every customer-facing turn.
- [ ] Contract test: any tool that puts money into its `FactBag` either
      returns `retainInSummary() === true` or sits on an explicit exemption
      list.

## Out of scope

- Model-generated summaries (a second model call per turn is the cost we are
  trying to remove).
- Prompt caching flags per provider — recorded as a follow-up in `10`.
