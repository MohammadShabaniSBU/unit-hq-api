# S25-01 — `ArgumentProvenance` guard

**Depends on:** S25-00
**Touches:** `unit-hq-api`
**Trace evidence:** trace-27 (returned classes 1, 4, 6, 7, 8, 10, 11), trace-34
(`unit_class_id: 1`, licensed), trace-35 (`unit_class_id: 2`, **fabricated**)

## Problem

The agent called `pricing.quote` twice: once for `unit_class_id: 1` (Trastero
5 m², legitimately returned by `facility.availability`) and once for
`unit_class_id: 2` — an id no tool had ever returned. It appears to have been
guessed by sequential inference from `1`.

The call succeeded. Postgres had a row for class 2, the pricer priced it, and
the customer was shown **€290.69 for a product that may not exist at that site
and was certainly never offered to them**. The agent's own hedge —
*"I'm not able to confirm exact unit class IDs"* — did not stop it.

Every guard on that turn returned `pass`: `duplicate_draft`, `grounding`,
`forbidden_claim`, `disclosure`, `channel` (trace-36 … trace-40).

**This is a design hole, not a bug.** S24's grounding guard licenses *claims*
against tool *results*. Nothing licenses tool *arguments*. A fabricated
identifier produces a real tool result, and a real tool result grounds
perfectly. The guard set is structurally incapable of catching this class of
error, and it is the class most likely to produce a commercial dispute.

## What to build

### `App\Support\Agents\Guards\ArgumentProvenance`

Implements the existing guard interface. Runs pre-dispatch, immediately after
schema validation and before idempotency (see S25-00 gate ordering).

An entity identifier appearing in a tool argument must be licensed by one of:

1. **A prior `ToolResult::entities` entry** in this conversation, matching type.
2. **An explicit user statement** — the customer wrote
   *"give me the details of site with id 1"*, and that licenses `site:1`.
3. **Conversation context** — the site derived from the inbound channel
   (S25-02), or a site/contract/unit reachable from an identified contact.

Anything else: verdict `deny`, reason `unlicensed_argument`, detail
`{argument, value, type, licensed: [...]}`. The dispatcher converts the denial
into a `ToolError` whose `recovery.tool` names the tool that *would* license
that entity type — `facility.availability` for `unit_class`,
`facility.find_sites` for `site` — so the model self-repairs rather than
apologising to the customer.

**Deny, not warn.** A warn verdict here is worth nothing: the tool still runs
and the customer still sees the number.

### `App\Support\Agents\FactRegistry`

Accumulates licensed `EntityRef`s for a conversation.

**Scope is conversation, not turn.** This is a deliberate divergence from S24's
claim licences, which are turn-scoped and never persisted. Availability came
back two turns before the quote; a turn-scoped registry would deny the
*correct* call as well as the fabricated one. Rebuild the registry from the
persisted trace on conversation load rather than adding a table — the trace is
already append-only and already holds `entities` after S25-00.

Sources:

- `ToolResult::entities` from every prior tool invocation in the conversation.
- `UserStatedIdentifiers` — a narrow extractor for explicit id mentions in
  inbound message bodies (`site 1`, `site with id 1`, `unit A-114`). Narrow on
  purpose: this is a licence grant, so a loose parser is a hole. Anything it
  does not confidently match is simply not licensed, and the agent asks.
- Channel/contact context resolved at conversation start.

### Per-tool declaration

Each tool declares which arguments are entity references:

```php
public function entityArguments(): array
{
    return ['unit_class_id' => 'unit_class', 'site_id' => 'site'];
}
```

Coverage test: every tool argument whose name matches `*_id` must either be
declared in `entityArguments()` or explicitly listed in a documented exemption
set (e.g. `tax_rate_id` on an internal path). Same discipline as
`SubjectSiteCoverageTest` — an undeclared id argument fails the build rather
than silently bypassing the guard.

## Acceptance criteria

- [ ] Replaying the attached trace **denies** invocation 5 (`unit_class_id: 2`)
      with reason `unlicensed_argument`.
- [ ] Replaying the attached trace **passes** invocation 4 (`unit_class_id: 1`),
      licensed by trace-27.
- [ ] Replaying the attached trace **passes** invocation 2
      (`facility.site_info`, `site_id: 1`), licensed by the customer's own
      message.
- [ ] A denial returns a `ToolError` naming the licensing tool, and an eval
      fixture shows the agent recovering by calling it instead of escalating.
- [ ] Coverage test fails when a new tool adds an undeclared `*_id` argument.
- [ ] Registry survives a wait/resume or reload — rebuilt from trace, not held
      in memory only.

## Out of scope

- Licensing non-identifier arguments (dates, amounts, free text). Identifiers
  first; the failure mode for those is different and needs its own reasoning.
- Widening `ForbiddenClaimKey` — S25-07.

## Invariants

Introduces invariant 64 (drafted in S25-08): an entity identifier in a tool
argument must be licensed by a prior tool result, an explicit user statement, or
conversation context. Never inferred, never sequential, never carried only in
the model's own bookkeeping.

This is the input-side mirror of S24's claim licensing. **Reuse that plumbing —
do not fork it.** One home per rule.
