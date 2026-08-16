# S22-00 — Schema, models, permission

**Repo:** `unit-hq-api`
**Depends on:** —
**Blocks:** everything else in S22

## Goal

Land the persistence and authorization vocabulary for agent conversations.
Every table here is production-shaped — nothing is demo-only except the values
that will land in `origin`.

## Migrations

### `ai_agents`

Instances, not definitions (D-AI-6). A real model so it can be an
`activity_log` causer when agents start writing (D-AI-4).

| Column | Type | Notes |
|---|---|---|
| `id` | pk | |
| `key` | string, unique | `support` \| `sales`. Must resolve to an `AgentDefinition` |
| `name` | string | operator-facing |
| `description` | text nullable | |
| `model` | string | provider model id, e.g. `claude-sonnet-4-6` |
| `is_active` | bool, default true | |
| `settings` | jsonb nullable | tuning knobs only (max turns override, temperature). **Never** the prompt or tool list |
| `archived_at` | timestamp nullable | archive-only, no hard delete |
| timestamps | | |

### `agent_conversations`

| Column | Type | Notes |
|---|---|---|
| `id` | pk | |
| `ai_agent_id` | FK → `ai_agents` | restrict on delete |
| `audience` | enum `internal` \| `customer`, **not null** | |
| `origin` | enum `demo` \| `inbox` \| `webchat`, **not null** | D-AI-7 |
| `channel` | enum `email` \| `sms` \| `whatsapp` \| `webchat` \| `internal`, not null | drives `ChannelProfile` |
| `employee_id` | FK nullable | **principal** for `internal` audience |
| `created_by_employee_id` | FK nullable | who *ran* the conversation — the demo harness operator. Distinct from the principal |
| `contact_id` | FK nullable | principal for `customer` audience |
| `site_id` | FK nullable | scoping context for tools |
| `verification_level` | enum `anonymous` \| `channel_asserted` \| `verified`, not null | |
| `state` | enum `active` \| `awaiting_human` \| `handed_off` \| `closed`, not null, default `active` | |
| `locale` | string(5) nullable | falls back to contact → site → app locale |
| `message_thread_id` | FK nullable | **always null this sprint** (D-AI-3) |
| `last_turn_at` | timestamp nullable | |
| `closed_at` | timestamp nullable | |
| timestamps | | |

**CHECK constraints** (same technique as invariant 50 — bind the shape in the
database, not in a request rule):

```sql
CHECK (audience <> 'internal' OR (employee_id IS NOT NULL AND contact_id IS NULL))
CHECK (audience <> 'customer' OR employee_id IS NULL)
CHECK (verification_level <> 'verified' OR contact_id IS NOT NULL)
CHECK (origin <> 'demo' OR created_by_employee_id IS NOT NULL)
```

Indexes: `(origin, created_at)`, `(ai_agent_id, created_at)`,
`(contact_id)`, `(state)`.

> SQLite honours `CHECK` on create, so the in-memory test path enforces these
> too. Write them in the `Schema::create` closure via raw `DB::statement` only
> where Postgres-specific; prefer portable `CHECK` expressions.

### `agent_conversation_messages`

| Column | Type | Notes |
|---|---|---|
| `id` | pk | |
| `agent_conversation_id` | FK, cascade | |
| `sequence` | integer | unique with conversation id |
| `role` | enum `system` \| `user` \| `assistant` \| `tool` | |
| `content` | text nullable | |
| `tool_calls` | jsonb nullable | assistant turns that requested tools |
| `tool_call_id` | string nullable | correlates a `tool` row to its request |
| `model` | string nullable | |
| `input_tokens` / `output_tokens` | integer nullable | |
| `latency_ms` | integer nullable | |
| `finish_reason` | string nullable | |
| `blocked_by` | string nullable | guardrail key when a draft was suppressed (`S22-03`) |
| `emitted_message_id` | FK → `messages` nullable | **always null this sprint**; the seam for S23 |
| `created_at` | timestamp | no `updated_at` — append-only in practice |

Unique: `(agent_conversation_id, sequence)`.

### `agent_tool_invocations`

The "what did it look at" table. When a customer complains in S23, this must be
answerable in one query.

| Column | Type | Notes |
|---|---|---|
| `id` | pk | |
| `agent_conversation_id` | FK, cascade | |
| `agent_conversation_message_id` | FK nullable | the assistant turn that requested it |
| `tool_key` | string | e.g. `billing.balance` |
| `arguments` | jsonb | |
| `result` | jsonb nullable | structured `ToolResult::$data` |
| `result_summary` | text nullable | the rendered display string |
| `status` | enum `ok` \| `denied` \| `not_found` \| `error` | |
| `denied_reason` | string nullable | `verification` \| `ownership` \| `not_allowed_for_agent` \| `site_scope` |
| `required_verification` | string nullable | snapshot — so "why was this denied" survives a policy change |
| `principal_verification` | string nullable | snapshot |
| `duration_ms` | integer nullable | |
| `created_at` | timestamp | |

Index: `(agent_conversation_id, created_at)`, `(tool_key, status)`.

### `agent_handoffs`

| Column | Type | Notes |
|---|---|---|
| `id` | pk | |
| `agent_conversation_id` | FK, cascade | |
| `reason` | enum (below) | |
| `trigger_source` | enum `rule` \| `model` \| `customer` \| `guardrail` | |
| `detail` | jsonb nullable | matched rule key, offending token, tool that failed |
| `employee_id` | FK nullable | assignee — null this sprint |
| `resolved_at` | timestamp nullable | |
| `created_at` | timestamp | |

`HandoffReason`: `legal_or_complaint`, `delinquency`, `price_negotiation`,
`verification_required`, `unsupported_intent`, `grounding_failure`,
`repeated_failure`, `customer_requested`, `out_of_hours`, `budget_exceeded`,
`error`.

### `ai_usage_events` — extension

Additive migration only.

- add `ai_agent_id` FK nullable
- add `agent_conversation_id` FK nullable
- make `employee_id` nullable **if it is not already**
- add `CHECK (employee_id IS NOT NULL OR ai_agent_id IS NOT NULL)`

Invariant 48's wording ("attributes spend between employees") is amended in
`S22-07` to "between employees **or agents**". Everything else about invariant 48
holds unchanged: still telemetry, still mutable `status`, still no
`NUMERIC(10,2)` money path, still never summed across currencies (invariant 30).

## Models

`App\Models\AiAgent`, `AgentConversation`, `AgentConversationMessage`,
`AgentToolInvocation`, `AgentHandoff`.

- `AiAgent` — `archived_at` scope helpers, `definition(): AgentDefinition`
  resolving through the registry, `scopeActive()`.
- `AgentConversation` — casts for every enum, `messages()` ordered by
  `sequence`, `toolInvocations()`, `handoffs()`, `principal(): AgentPrincipal`
  builder, `isCustomerFacing()`.
- No `HasAutomationTriggers` on any of these. Agent tables must not fire
  automation triggers — that is a loop waiting to happen.
- Register `ai_agent` in the **morph map** only if a polymorphic relation
  actually needs it this sprint. It does not. Do not add it speculatively
  (invariant 15 reasoning).

## Enums

Under `App\Support\Ai\`:

`AgentAudience`, `AgentOrigin`, `AgentChannel`, `VerificationLevel`,
`ConversationState`, `HandoffReason`, `HandoffTriggerSource`,
`ToolInvocationStatus`, `ToolDeniedReason`.

`VerificationLevel` carries the ordering:

```php
enum VerificationLevel: string
{
    case Anonymous = 'anonymous';
    case ChannelAsserted = 'channel_asserted';
    case Verified = 'verified';

    public function rank(): int { … }          // 0, 1, 2
    public function satisfies(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }
}
```

`satisfies()` is the only comparison anyone may write. String comparison or
`in_array` checks against verification levels are defects — grep for them in
review.

## Permission

Add **one** case: `Permission::AiAgentUse`.

- Register in `RoutePermissions` (`S22-04` adds the routes; if `S22-00` merges
  first, grant it to the `owner` system role so `PermissionCoverageTest`
  passes — every enum case must appear in a manifest entry, policy, or system
  role, invariant 43).
- Mirror in panel `app/types/permissions.ts` or `PanelPermissionMirrorTest`
  fails both ways.
- Do **not** add `AiAgentManage` yet. An enum case with no route and no role
  fails coverage.

Seed grant: `owner`. `site_manager` is a judgement call — recommend yes, so the
demo can be given from a non-owner account.

## Seeder

`AiAgentSeeder` — two rows, idempotent (`updateOrCreate` on `key`):

| key | name | model |
|---|---|---|
| `support` | Support Agent | from `config('ai.default_model')` |
| `sales` | Sales Agent | from `config('ai.default_model')` |

Runs in `DatabaseSeeder`. Also referenced by `demo:seed` — but **no random
draws**, and it must not touch `StageSeeder`'s deterministic path.

## Config

New `config/ai.php`:

```php
return [
    'default_model'   => env('AI_DEFAULT_MODEL', 'claude-sonnet-4-6'),
    'demo_enabled'    => env('AI_DEMO_ENABLED', false),
    'max_turns'       => 20,
    'max_tool_calls_per_turn' => 6,
    'turn_timeout_ms' => 60_000,
    'conversation_token_budget' => 200_000,
];
```

`demo_enabled` defaults **false**. `origin = demo` is refused when it is off
(`S22-04`).

## Tests

- `AgentConversationConstraintTest` — each CHECK rejects its bad shape
  (`internal` with a `contact_id`, `customer` with an `employee_id`, `verified`
  with no contact, `demo` with no creator).
- `VerificationLevelTest` — `satisfies()` ordering, both directions.
- `AiAgentSeederTest` — idempotent, two rows, both keys resolve to a definition
  once `S22-01` lands (assert via the registry; skip until then).
- `PermissionCoverageTest` / `PanelPermissionMirrorTest` green.

## Acceptance

- [ ] Migrations run clean on SQLite in-memory and on Postgres.
- [ ] All four CHECK constraints reject their bad shape at the database level,
      not only in a request rule.
- [ ] `ai_usage_events` accepts an agent-attributed row with no `employee_id`
      and rejects a row with neither.
- [ ] `Permission::AiAgentUse` exists, is granted to `owner`, mirrors to the
      panel, and coverage tests are green.
- [ ] No agent table fires an automation trigger.
