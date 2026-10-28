# S25-00 — Tool result contract: identity echo and structured errors

**Depends on:** nothing
**Blocks:** S25-01, S25-02, S25-03, S25-07
**Touches:** `unit-hq-api`
**Trace evidence:** trace-13, trace-34, trace-35

## Problem

Two failures in the reviewed conversation trace back to the same missing
abstraction — tools return whatever shape their author chose.

**Results carry no identity.** `pricing.quote` returns
`{unit_class_id, net, tax, gross, rate, currency}`. Nothing in that payload
says *Trastero 5 m²* or *Madrid Centro*. The model is therefore required to
maintain an id→label map across turns from a `facility.availability` result two
turns earlier. It failed, quoted the wrong id, and told the customer
*"I'm not able to confirm exact unit class IDs"* while showing the price anyway.

**Errors are prose.** `facility.site_info` failed with
`site_id is required when no site is in context.` — a developer message with no
machine code and no recovery affordance. The agent had no way to know another
tool existed that could resolve the site, so it offered human handoff and the
conversation dead-ended at turn three.

A third, smaller defect: the call was made with `arguments: []` and reached
application code at all, which means required parameters are not enforced at
the tool-schema layer. The empty-array serialisation is also wrong — an empty
PHP array encodes as `[]`, and the argument bag is an object.

## What to build

### `App\Support\Agents\Tools\ToolResult`

Value object every tool returns.

| Field | Type | Notes |
|---|---|---|
| `ok` | bool | |
| `summary` | string | The single line fed to the model. Already exists as `result_summary` in the trace |
| `data` | array | Structured payload; persists to the trace, **not** into model context (see S25-05) |
| `entities` | `Array<EntityRef>` | Every entity this result names |

`EntityRef`: `{ type, id, label, context }` — e.g.
`{type: 'unit_class', id: 4, label: 'Trastero 8 m²', context: 'Madrid Centro'}`.
This is the licence registry S25-01 reads; it is not decoration.

**Rule:** a result that names an entity must list it in `entities`. Enforced by
contract test, not review.

### `App\Support\Agents\Tools\ToolError`

| Field | Type | Notes |
|---|---|---|
| `error_code` | `ToolErrorCode` enum | Machine key, i18n-translatable in the panel |
| `message` | string | Developer-facing, trace only, never shown to a customer |
| `recovery` | `?array` | `{tool, hint}` — the tool that would unblock this call |
| `candidates` | `Array<EntityRef>` | When the failure is ambiguity rather than absence |

Initial `ToolErrorCode` cases: `site_unresolved`, `unlicensed_argument`,
`not_found`, `ambiguous`, `invalid_arguments`, `unavailable`, `price_superseded`.
Grow deliberately; a new case is a decision, not a convenience.

### Schema validation in the dispatch gate

Argument validation runs **before** idempotency. Ordering rationale mirrors
S24's idempotency-before-quota reasoning: a malformed call is not a retryable
write and must never consume an idempotency slot or a quota unit.

Gate order after this task:

```
schema validation → argument provenance (S25-01) → idempotency → quota → write policy
```

Serialise the argument bag as a JSON object always — `{}` when empty.

### Migration of existing tools

All registered tools return `ToolResult` / `ToolError`. The trace adapter maps
`ToolResult` onto the existing row shape (`status`, `denied_reason`,
`result_summary`, `result`) so the persisted trace format does not change in
this task — S25-05 changes it deliberately.

## Acceptance criteria

- [ ] Every tool in the registry returns `ToolResult` or `ToolError`.
- [ ] Contract test iterates the tool registry: each tool has a golden fixture
      whose result declares `entities` for every entity id in its payload.
- [ ] `facility.site_info` with no arguments returns `error_code: site_unresolved`
      with `recovery.tool = 'facility.find_sites'` (the tool arrives in S25-02;
      the recovery reference may be asserted against the registry once it exists).
- [ ] A tool call missing a required argument is rejected at the schema step,
      never reaches the handler, and consumes no idempotency slot or quota.
- [ ] Trace shows `"arguments": {}` for an empty bag.
- [ ] `pricing.quote` and `facility.availability` results include labels and
      site names.

## Out of scope

- Changing the persisted trace row shape — S25-05.
- Adding new tools — S25-02, S25-07.
- Acting on `entities` — S25-01.

## Invariants

Introduces invariant 65 (drafted in S25-08): a tool failure returns a machine
error code and a recovery affordance; a prose-only failure is a defect, because
it forces escalation where retry was available.
