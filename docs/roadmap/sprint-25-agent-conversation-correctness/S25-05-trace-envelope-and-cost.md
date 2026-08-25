# S25-05 — Trace envelope, usage cost resolution, context discipline

**Depends on:** nothing (parallel with S25-00)
**Blocks:** S25-06
**Touches:** `unit-hq-api`
**Trace evidence:** trace-6, 12, 19, 26, 33, 41 and the export as a whole

## Problem

### Cost attribution is non-functional

Every `usage` row in the export:

```json
{ "kind": "usage", "input_tokens": 2653, "output_tokens": 22,
  "estimated_cost": null, "currency": null }
```

Six rows, six nulls. Invariant 48 requires estimated cost to be derived at read
time from `ai_model_prices` — an effective-dated catalogue following the
invariant 2 pattern. Either the model key in use has no price row, or the
effective-dated lookup is not wired. Whichever it is, the feature that
attributes AI spend between employees currently attributes nothing.

### The trace cannot be replayed

The export is a flat array. No `conversation_id`, no `turn`, no `model`, no
`prompt_version`, no timestamps. Guardrail rows carry no `message_id`, so the
only way to associate a guard verdict with the message it judged is **array
order**. That is fragile now and unusable the moment anything is queued,
retried, or paginated.

These are precisely the fields the eval and replay tables need. Fixing them here
rather than duplicating the work later.

### Context grows without bound

Input tokens across six trivial turns: 2,653 → 2,703 → 5,608 → 5,994 → 6,703 →
7,549. Nearly tripled while the conversation established a postcode, a site, and
two prices. The cause is visible in the trace: full `result` payloads re-enter
model context each turn — `facility.availability` alone returns seven class
objects with site name and id repeated on each.

## What to build

### Cost resolution

- Diagnose the null. Likely candidates: model key absent from `ai_model_prices`,
  or the resolver not filtering on the effective window correctly.
- Add `agents:check-model-prices` — fails when any model key used in the last 30
  days has no `ai_model_prices` row effective for that period. Wire into
  scheduled checks so a model swap cannot silently null out cost again.
- Cost stays **derived at read time**. Never stored, never a `NUMERIC(10,2)`
  money column, never reconciled against the provider invoice (invariant 48).
- Never return a single summed cost across currencies (invariant 30).

### Trace envelope

Every trace row gains:

| Field | Notes |
|---|---|
| `conversation_id` | |
| `turn` | Monotonic within the conversation |
| `message_id` | Nullable — null for rows that precede a message |
| `model` | The model that produced the turn |
| `prompt_version` | Stable identifier for the system prompt in force |
| `occurred_at` | |

Guardrail rows link to the message they judged. Tool rows keep
`invocation_id`, `pending_action_id`, `replayed` as they are, and gain
`entities` from S25-00 plus, on failure, `error_code` and `recovery`.

Usage rows additionally record `cached_input_tokens` where the provider reports
them, because otherwise cost estimates drift as prompt caching engages.

### Context discipline

The model receives **`summary` only**; `data` persists to the trace. Where a
later tool needs a field from an earlier result, it comes through `FactRegistry`
(S25-01) — the registry is already the licensed, structured view of what has
been established.

Verify with a replay fixture: the same conversation, driven on summaries alone,
must produce the same tool calls.

## Acceptance criteria

- [ ] No `estimated_cost: null` for a model with an effective catalogue row.
- [ ] A model without a price row raises `agents:check-model-prices` failure
      rather than silently nulling.
- [ ] Every guardrail row resolves to a message; no consumer relies on array
      order.
- [ ] Trace rows carry conversation, turn, model, prompt version, timestamp.
- [ ] Replaying the reviewed conversation on summaries-only reproduces the same
      tool sequence.
- [ ] Input tokens for an equivalent six-turn conversation drop measurably
      against the 7,549 baseline; record the figure in the task's PR.
- [ ] Cost figures are grouped by currency, never summed across (invariants 30,
      48).

## Out of scope

- The eval suite tables (`agent_eval_suites` / `_cases` / `_runs` / `_results`).
  This task makes them possible by giving replay a stable envelope; building
  them is its own sprint.
- Extending `contacts:redact` to agent conversation tables. Known gap, tracked
  in `10-open-decisions.md`, and it is a compliance defect rather than a trace
  concern — it needs its own task with its own review.
