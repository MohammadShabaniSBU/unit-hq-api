# S26-06 — `agent_channel_bindings`: which agent answers where

**Depends on:** nothing
**Blocks:** S26-07, S26-08
**Touches:** `unit-hq-api` (incl. seeders)

## Problem

There is no operator control for whether an agent answers a real channel.
`config('agents.enabled')` and `ai_agents.is_active` are global kill
switches; neither says *the sales agent may answer SMS at Madrid Centro but
only for senders we already know, and everything else goes to the Inbox*.
That is the control the operator asked for, and S26-07 must have it to check
before it can be written.

## What to build

### Table

`agent_channel_bindings`:

| Column | Type | Notes |
|---|---|---|
| `ai_agent_id` | FK `ai_agents` | |
| `channel` | string enum (`AgentChannel`: `email`, `sms`, `whatsapp`, `webchat`) | Bindable set is `AgentChannel::bindable()`. `voice` **and** `internal` rejected at validation with the same 422 |
| `site_id` | FK `sites`, nullable | null = company-wide sender identity / company-scoped account |
| `mode` | `off` \| `draft` \| `auto` | `draft` = reply lands as a pending action in the Inbox; `auto` = sent in the turn |
| `audience` | `known_contacts` \| `existing_tenants` \| `all` | who the agent will answer (below) |
| `outside_hours` | `inbox` \| `answer` | `inbox` = no turn outside the company send window (S26-07) |
| `updated_by_employee_id` | FK `employees` nullable | |
| `archived_at` | nullable | archive-only |
| timestamps | | |

Partial unique `(channel, site_id) WHERE archived_at IS NULL` with
`site_id` coalesced (Postgres: `COALESCE(site_id, 0)`) — **one agent per
channel per site**. Routing between agents (support before sales) happens
*within* a binding's agent when `audience` permits; two bindings on the same
`(channel, site)` is a configuration error, not a priority list.

**Absent row = `off`.** This is deliberately the opposite default from
`agent_write_policies` (absent = commit). A missing binding means an agent
talks to real customers; that must be an explicit act.

### `audience` semantics

| Value | Who gets an agent turn |
|---|---|
| `known_contacts` | Inbound matched a `contact_channels` row (principal `channel_asserted`). Unmatched → `comms_triage` exactly as today |
| `existing_tenants` | As above **and** the contact has an active contract at the binding's site (company-wide: any site). Prospects → Inbox |
| `all` | Matched contacts, plus unmatched senders under the auto-lead-capture policy from S26-07. Unmatched without that policy → triage |

### Resolution (`AgentChannelBindings::resolve(channel, ?siteId)`)

Site-scoped live row → company-scoped live row → `null` (off). Mirrors
`ProviderResolver`. Result is a value object `{agent, mode, audience,
outside_hours}`; the listener never reads the table directly.

### Kill switches, in order

`config('agents.enabled')` → `ai_agents.is_active && !archived` → binding
present and `mode != off`. `AgentRuntime::turn` keeps its own checks; the
binding check is the listener's, and the runtime additionally refuses
`origin = inbox` turns whose conversation has no resolvable binding
(defence in depth, invariant 56 spirit).

### API

`GET/POST /api/ai/agents/bindings`, `PUT/DELETE /api/ai/agents/bindings/{binding}`
(`DELETE` = archive). Sibling of `PUT /api/ai/agents/{aiAgent}/write-policies`,
**not** nested under `{aiAgent}` — a binding is unique per `(channel, site)`
across agents, so the list must show every agent's bindings together. Panel
route in S26-08 is unchanged. Permission: new `Permission::AiAgentBindingManage`
(`ai_agent_binding.manage`), added to system role `owner`; panel mirror
updated (`PanelPermissionMirrorTest`). Tier-3 `RecordsActivity::core`
`ai.binding.created` / `.updated` / `.archived` with `{channel, site_id,
mode, audience}`.

### Seeder

`AgentChannelBindingSeeder`, called from `AiAgentSeeder::run()` so both
`DatabaseSeeder` and `DemoPipeline` pick it up (DemoPipeline already runs
`AiAgentSeeder` after `StageSeeder`, line ~43). Rows (deterministic,
`updateOrCreate` on `(channel, site_id)` — the same tuple as the partial
unique index, so a re-run stays idempotent even if the owning agent changes):

| Agent | Channel | Site | mode | audience | outside_hours |
|---|---|---|---|---|---|
| sales | webchat | null | `auto` | `all` | `answer` |
| sales | sms | null | `draft` | `known_contacts` | `inbox` |
| sales | whatsapp | null | `draft` | `known_contacts` | `inbox` |
| support | email | null | `draft` | `existing_tenants` | `inbox` |

No `auto` on a provider channel in the demo world. `DemoScript` gains a
paragraph explaining the four rows so presenters know why WhatsApp replies
appear as approvals.

## Acceptance criteria

- [ ] Migration up/down, Postgres + SQLite; partial unique enforced.
- [ ] `resolve('sms', siteId)` prefers the site row, falls back to company,
      returns null when neither exists; archived rows ignored.
- [ ] Creating a second live binding for the same `(channel, site)` → 422.
- [ ] Routes covered by `RouteAuthCoverageTest` / `PermissionCoverageTest`.
- [ ] `php artisan demo:seed --fresh` and `db:seed` both produce the four
      rows; re-run is idempotent.
- [ ] Docs: `14-ai-agents.md` "Not built → per-agent, per-channel autonomy"
      line removed in S26-09.

Introduces **invariant 68** (S26-09).

## Out of scope

- Panel UI — S26-08.
- The listener that consumes bindings — S26-07.
