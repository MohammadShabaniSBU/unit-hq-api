# S24-02 — Write policy, quotas, idempotency

**Repo:** `unit-hq-api`
**Depends on:** `S24-00`
**Blocks:** `S24-03`, `S24-04`, `S24-05`, `S24-07`

## Goal

A per-agent, per-tool write policy that the dispatcher enforces **before**
`AgentTool::handle()` is reached, plus the idempotency that makes a retried tool
call safe to run twice.

Today a write tool is either in `AgentDefinition::toolKeys()` or it is not.
That binary is fine for `crm.create_task`. It is not fine for a tool that takes
inventory off the market.

## Why not `ai_agents.settings`

Invariant 58: `settings` holds tuning knobs only, never the prompt or the tool
list. A per-tool autonomy mode with quotas is effectively a permission, and
permissions are not JSON. It gets a table.

## Migration — `agent_write_policies`

| Column | Type | Notes |
|---|---|---|
| `id` | pk | |
| `ai_agent_id` | FK → `ai_agents`, cascade | |
| `tool_key` | string | must resolve in `ToolRegistry` — coverage test, not FK |
| `mode` | string, not null | `off` \| `propose` \| `commit` |
| `max_per_conversation` | integer nullable | null = unlimited |
| `max_per_day` | integer nullable | null = unlimited; per agent, per calendar day in **app** timezone |
| `min_verification` | string nullable | may **raise** the tool's floor, never lower it |
| `updated_by_employee_id` | FK nullable | who changed the autonomy |
| timestamps | | |

Unique: `(ai_agent_id, tool_key)`. Index: `(ai_agent_id)`.

`min_verification` raise-only cannot be a CHECK — the database cannot see the
tool's declared floor. Enforce in `AgentWritePolicy::effectiveVerification()`:

```php
return $this->min_verification === null
    ? $tool->requiredVerification()
    : max_by_rank($tool->requiredVerification(), VerificationLevel::from($this->min_verification));
```

Use `VerificationLevel::satisfies()` for the comparison. String comparison
against verification levels is a defect — grep for it in review.

**Absent row = `commit`, unlimited.** The default must not change today's
behaviour for `crm.create_contact` / `create_deal` / `create_task`. Seeding a
restrictive default for existing tools is a behaviour change smuggled into a
schema task; do not.

`S24-04` / `S24-05` seed a `propose` row for their own tool keys explicitly.

## Migration — `agent_tool_invocations` extension

Additive.

| Column | Type | Notes |
|---|---|---|
| `idempotency_key` | string nullable | `sha256(conversation_id \| tool_key \| canonical_json(normalised_args))` |
| `result_type` | string nullable | morph alias of what the tool created |
| `result_id` | bigint nullable | |

Unique: `(agent_conversation_id, idempotency_key)` **partial, where
`idempotency_key IS NOT NULL` and `status = 'ok'`**. Only successful writes are
deduplicated; a denied call must be retryable.

Key is computed on the **normalised** arguments (after schema validation and
type coercion), not the raw model output — otherwise `"1"` and `1` produce two
keys for one intent.

Only write tools set it. Read tools leave it null.

## `AgentWritePolicyGate`

A new class in `App\Support\Ai\Guards\`, called from `ToolDispatcher::dispatch`.

The dispatcher's gate order becomes:

1. tool in `AgentDefinition::toolKeys()` → else `denied: not_allowed_for_agent`
2. **policy `mode = off`** → `denied: not_allowed_for_agent`
3. verification satisfies `effectiveVerification()` (policy-raised floor) →
   else `denied: verification`
4. arguments validate against `schema()` → else `error`
5. contact-scoped arguments match `ownsContact()` → else `denied: ownership`
6. **quota check** (write tools only) → else `denied: quota_exceeded`
7. **idempotency check** → replay the prior `ToolResult` if the key already
   committed in this conversation
8. `handle($principal, $arguments, $ctx)`

Gates 2, 6 and 7 are new. Everything else keeps its current position and
meaning. Gate 3 is the *only* one that changes semantics, and only by raising.

New `ToolDeniedReason` cases: `QuotaExceeded`, `RequiresApproval`.
`RequiresApproval` is returned by `S24-03` for `mode = propose`; add the enum
case here so the two tasks can land in either order.

**Quota counting** reads `agent_tool_invocations` where `status = 'ok'` — the
committed writes, not attempts. A denied call must not burn quota, or a
confused model exhausts the budget without creating anything.

**Idempotency replay** returns a `ToolResult::ok` reconstructed from the stored
`result` / `result_summary` / fact keys, with a new `replayed: true` flag in
`data`. It must not re-run `handle()`, and it must not write a second invocation
row — it appends to the existing one's trace or writes a row marked as a replay.
Pick one and document it; do not leave it ambiguous.

## Model

`App\Models\AgentWritePolicy` — casts for `mode` and `min_verification`,
`scopeForTool()`, `effectiveVerification(AgentTool $tool)`, `allows(): bool`.

No `HasAutomationTriggers`. Agent tables do not fire automation triggers.

## Tests

- `AgentWritePolicyTest` — `min_verification` raises a floor; a policy trying to
  *lower* a floor is ignored (assert the effective level, not the stored one);
  absent row behaves as `commit` unlimited.
- `ToolDispatchTest` — extend for the three new gates. Specifically: `mode = off`
  denies before `handle()` **and touches no database rows** (same
  `DB::getQueryLog()` assertion the verification gate already uses).
- `AgentQuotaTest` — `max_per_conversation` counts only `status = ok`; a denied
  call does not decrement; `max_per_day` rolls at app-timezone midnight.
- `AgentIdempotencyTest` — the same normalised args twice in one conversation
  produce one row in the target table and a `replayed: true` second result;
  `"1"` and `1` produce the same key; different conversations do not collide.
- `AgentToolCoverageTest` — extend: every `agent_write_policies.tool_key` in the
  seeder resolves in `ToolRegistry`.

## Acceptance

- [ ] Existing agent behaviour is byte-identical with an empty
      `agent_write_policies` table. The full S22 suite passes untouched.
- [ ] `mode = off` denies with no query log entries.
- [ ] A policy cannot lower a tool's declared verification floor, only raise it.
- [ ] A retried identical write produces one row in the target table.
- [ ] Denied calls do not consume quota.
- [ ] `ToolDeniedReason` gains `QuotaExceeded` and `RequiresApproval`, both
      surfaced in `agent_tool_invocations.denied_reason`.
