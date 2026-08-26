# S26-00 — Entity refs are visible to the model

**Depends on:** nothing
**Blocks:** S26-01, S26-02, S26-03
**Touches:** `unit-hq-api`
**Trace evidence:** trace-7, trace-15, trace-29, trace-30

## Problem

`AgentRuntime` feeds the model only `ToolResult::$display`
(`AgentRuntime.php` ~line 254, `wrapUntrusted($result->display)`). `data` and
`entities` stay off-context by design (invariant 65). But every tool schema
takes integer ids, and `ArgumentProvenance` only licenses ids that appeared in
a prior result's `entities`. The model is therefore required to pass ids it has
never been shown.

- `facility.find_sites` display: `Madrid Centro, 28004; Madrid Norte, 28036; …`
  — no `site_id`. The model passed `site_id: 1`. A guess.
- `facility.availability` display: `3 units available in Trastero 16 m² XL
  (16.00 m²) at Madrid Centro as of now.` — no `unit_class_id`. Asked to price
  the 16 m² XL the model called `pricing.quote(unit_class_id: 8)` (the 12 m²),
  re-ran availability, still had no id, and told the customer it could not
  fetch the price.

Provenance is currently licensing ids the model can only obtain by
hallucination. The guard is correct; the contract feeding it is incomplete.

## What to build

### `ToolResult` appends a refs line automatically

In `App\Support\Ai\Tools\ToolResult`, add a method `modelText(): string` that
returns `display` followed by one line rendered from `entities`:

```
3 units available in Trastero 16 m² XL (16.00 m²) at Madrid Centro as of now.
Refs: site 1 = Madrid Centro; unit_class 12 = Trastero 16 m² XL
```

Rules:

- Grouped by `EntityType`, ordered by type then id. Deterministic.
- `label` verbatim; `context` omitted (it is already in the prose).
- Empty `entities` → no `Refs:` line at all.
- Errors (`ToolError`) render `candidates` the same way under `Candidates:`
  — they still do **not** mint licences (S25 rule unchanged; provenance reads
  `entities` on `status = ok` rows only).
- `AgentRuntime` swaps `$result->display` for `$result->modelText()` in the
  tool message it appends to `$messages`. `result_summary` on
  `agent_tool_invocations` and in the SSE `tool.finished` event **stays
  `display`** — the refs line is model-facing, not operator-facing.
- `persistToolMessage` (~line 761) stores `display`, unchanged.

Do **not** add ids into each tool's prose by hand. One renderer, one place,
so a new tool cannot forget.

### System prompt

`AssemblesSystemPrompt` gains one sentence in the shared tool-usage block:
*"Tool results end with a `Refs:` line. Use only those ids as arguments; if
the id you need is not in a `Refs:` line from this conversation, call the
tool that lists it first."*

### Cassettes

`CassetteKey` hashes the prompt template and tool schema, not `display`, so
the key does not change — but every recorded model response that used ids
was produced without refs visible. Re-record all fixtures under
`tests/Fixtures/agents/` with `agent:replay --live --record` **once, in this
task**, after the refs line and the prompt sentence are in. Downstream tasks
re-record only the fixtures they add.

## Acceptance criteria

- [ ] `ToolResultTest`: a result with entities renders a `Refs:` line grouped
      and sorted by type then id; a result without entities renders none.
- [ ] `AgentRuntimeTest`: the `role: tool` message content contains the
      `Refs:` line; the persisted `agent_conversation_messages.content` and
      `agent_tool_invocations.result_summary` do not.
- [ ] Contract test over the registry: for every tool golden fixture, every
      `*_id` in `data` has a matching entry on the `Refs:` line.
- [ ] Replay of the S26 fixture: after `facility.availability` returns class
      12, the next `pricing.quote` call passes `unit_class_id: 12`.
- [ ] `agent:replay` green with re-recorded cassettes; no
      `EvalCassetteStaleException`.

Introduces **invariant 67** (S26-09).

## Out of scope

- Feeding `data` to the model. Still never.
- Changing `entityArguments()` / provenance semantics.
