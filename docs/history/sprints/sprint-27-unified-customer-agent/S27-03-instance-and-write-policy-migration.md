# S27-03 — `ai_agents` instance rows and the write-policy merge

**Depends on:** S27-00
**Blocks:** S27-02
**Touches:** `unit-hq-api` (incl. seeders)

## Problem

`ai_agents` rows are instances, not definitions (D-AI-6, invariant 58). Two
instances exist and both carry state that cannot simply be dropped:

- `agent_conversations.ai_agent_id` is `restrictOnDelete`. Every historical
  conversation holds a reference. The rows cannot be deleted, and
  `AiAgent::definition()` resolves the key on every read of one.
- `agent_write_policies` is unique on `(ai_agent_id, tool_key)` and holds the
  operator's autonomy configuration. Collapsing two agents into one collapses
  two policy sets into one namespace, and seven tool keys are held by both:
  `facility.find_sites`, `facility.site_info`, `facility.size_guide`,
  `calendar.resolve`, `crm.create_task`, `kb.faq_lookup`, `agent.escalate`.
  Only `crm.create_task` is a write.

There is no defined precedence between two policies for the same tool key.
Picking one silently would widen autonomy somewhere without an operator
deciding to.

## What to build

### The `concierge` instance

`AiAgentSeeder` gains a third `updateOrCreate` on `['key' => 'concierge']`
with name `Customer Agent`, `model` from `config('agents.default_model')`,
`is_active = true`. Its write policies carry forward the two the sales
instance held, unchanged:

| tool_key | mode | max_per_conversation | max_per_day |
|---|---|---|---|
| `sales.create_offer` | `commit` | 2 | 50 |
| `sales.create_reservation` | `propose` | 1 | 20 |

The seeder keeps creating the `sales` and `support` rows, with
`is_active = false` and `archived_at` set. They must stay seeded, not just
survive in existing databases: a fresh `db:seed` on a restored production
dump has to render historical conversations, and `AgentDefinitionCoverageTest`
iterates `AiAgent::pluck('key')` and would otherwise stop covering them.

### Policy merge rule — strictest wins

A data migration copies both agents' `agent_write_policies` onto the
`concierge` row. Where both hold the same `tool_key`, take the **more
restrictive** value field by field:

| Field | Rule |
|---|---|
| `mode` | Lowest autonomy: `off` < `propose` < `commit` |
| `max_per_conversation` | Lower of the two; a null (unlimited) loses to any number |
| `max_per_day` | Same |
| `min_verification` | Highest of the two by `VerificationLevel::rank()`; null (tool default) loses to any explicit value |

Strictest-wins is the only rule that cannot widen autonomy as a side effect
of a merge. It can narrow it — an operator who had `crm.create_task` on
`commit` for support and `propose` for sales ends up with `propose` — and
that is the correct failure direction. Emit one Tier-3
`RecordsActivity::core` `ai.write_policy.merged` per merged key with
`{tool_key, from: [...], to: {...}}` so a narrowed setting is visible rather
than mysterious.

Rows for `sales` and `support` are **left in place**, not deleted. They are
the audit trail of what the merge read.

### Guard the definition resolution

`AiAgent::definition()` currently calls `AgentRegistry::get()` bare, which
throws `RuntimeException` on an unknown key. That is correct behaviour for a
defect but a 500 in the Inbox list is a bad way to surface it. Leave the
throw — invariant 58 says an unmapped key *is* a defect — and instead add
`AgentDefinitionCoverageTest` coverage that the archived rows still resolve,
so the class deletion that would cause it fails in CI rather than in
production. Add the class-level docblock to both legacy definitions (S27-00).

### Panel-facing naming

`ai_agents.name` is operator-visible. `Customer Agent` for `concierge`;
rename the archived rows to `Sales Agent (archived)` and
`Support Agent (archived)` so a conversation list showing a historical row
reads honestly. The `key` never changes — it is the registry lookup.

## Acceptance criteria

- [ ] `db:seed` produces three `ai_agents` rows: one live `concierge`, two
      archived; re-run idempotent.
- [ ] `demo:seed --fresh` produces the same three, deterministically.
- [ ] Migration merges policies with strictest-wins; verified by a test that
      seeds a deliberately conflicting pair (`commit`/`propose`,
      `null`/`5`, `null`/`verified`) and asserts the narrow result.
- [ ] One `ai.write_policy.merged` activity row per merged key.
- [ ] `sales` / `support` policy rows survive the migration untouched.
- [ ] An `agent_conversations` row pointing at the archived `sales` instance
      loads, renders in `GET /api/agent-conversations`, and resolves its
      definition.
- [ ] `AgentDefinitionCoverageTest` covers all three keys.

Reaffirms **invariant 58**; introduces no new invariant.

## Out of scope

- Repointing bindings — S27-02, which depends on this task having created the
  row.
- Deleting the legacy instances or their policies. Not this sprint, and
  probably not any sprint: `restrictOnDelete` on conversations is deliberate.
- The two-authorization-systems question (`agent_write_policies` vs RBAC
  grants), recorded as Undecided in `10-open-decisions.md`. Merging policies
  does not settle it and must not be read as settling it.
