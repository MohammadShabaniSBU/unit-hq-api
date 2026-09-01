# S27-02 — Routing: audience decides, eligibility stops dropping mail

**Depends on:** S27-03
**Blocks:** S27-05
**Touches:** `unit-hq-api` (incl. seeders)

## Problem

`RespondWithAgent` runs six gates before a turn. Two of them answer the same
question with different data and opposite defaults:

```php
if (! $this->audienceAllows($resolved, $contact, $siteId)) skip('audience');
...
if (! $definition->eligible($contact, $siteId))            skip('agent_ineligible');
```

`audienceAllows()` reads `agent_channel_bindings.audience` — operator
configuration, visible in the panel, auditable. `eligible()` reads a hardcoded
tenancy predicate in a PHP class. With one agent per `(channel, site)` the
second gate can only subtract from the first, and with the seeded email
binding (support + `existing_tenants`) it subtracts every prospect.

The seeded rows themselves are the live defect: three channels answer
prospects, one answers tenants, and the one that answers tenants is email —
the channel most prospects use.

## What to build

### `eligible()` becomes a no-op for the live agent

S27-00 makes `ConciergeAgentDefinition::eligible()` return `true`. Keep the
call site in `RespondWithAgent` — do not delete the gate. It still fires for
a historical conversation resumed against a legacy agent, and deleting it
would silently change behaviour for those. The `agent_ineligible` skip reason
stays in the vocabulary and stays counted; it should simply go to zero on
live traffic, which is the metric that proves this task landed.

### `audience` semantics under one agent

Unchanged in meaning, changed in effect — the values now select *who gets
answered*, not *which agent answers*:

| Value | Behaviour with `concierge` |
|---|---|
| `known_contacts` | Inbound matched a `contact_channels` row. Prospects and tenants both answered, at whatever verification they hold. Unmatched → `comms_triage`, as today |
| `existing_tenants` | As above, and `AgentEligibility::hasInForceContractAtSite()` must pass. Still the right setting for an operator who wants the agent nowhere near new business |
| `all` | Matched contacts plus unmatched senders under the auto-lead-capture policy (D-AI-20, `config('communications.auto_lead_capture')`, default off). Without that flag, unmatched still goes to triage |

No new enum values. `BindingAudience` is already the correct vocabulary; it
was just being second-guessed by `eligible()`.

### Binding repoint migration

A data migration, after S27-03 has inserted the `concierge` row:

1. For every live `agent_channel_bindings` row (`archived_at IS NULL`), set
   `ai_agent_id` to the `concierge` row's id. The partial unique on
   `(channel, COALESCE(site_id,0)) WHERE archived_at IS NULL` already
   guarantees at most one row per pair, so repointing cannot create a
   conflict. Assert that with a count check before the update and fail the
   migration loudly if it does not hold — a duplicate pair means the index
   was dropped somewhere and the repoint would violate it mid-flight.
2. Leave `mode`, `audience` and `outside_hours` untouched. Operators chose
   those; this migration is not the place to reinterpret them.
3. Set `updated_by_employee_id` to null and write one Tier-3
   `RecordsActivity::core` `ai.binding.updated` per row with
   `{channel, site_id, from_agent_key, to_agent_key: 'concierge'}` so the
   change is visible in the activity log rather than appearing as a silent
   config edit.

The migration must be idempotent — a second run is a no-op because the rows
already point at `concierge`.

Runtime note: the binding change is not merely tidy. `AgentRuntime::turn()`
(lines 102–111) already throws `Agent is not bound to this channel.` when an
inbox conversation's `ai_agent_id` is not the live binding's agent. After
this migration, every pre-existing inbox conversation hard-fails on its next
turn unless `RespondWithAgent::findOrCreateConversation()` repoints it first.
That repoint is mandatory, not optional. Pending actions stay on the legacy
agent; S27-03's merge must keep `sales.create_reservation` at no weaker than
`propose` / 1 / 20 so those rows do not widen.

### Seeder

`AgentChannelBindingSeeder` — same four rows, one agent, and email moves off
`existing_tenants`:

| Agent | Channel | Site | mode | audience | outside_hours |
|---|---|---|---|---|---|
| concierge | webchat | null | `auto` | `all` | `answer` |
| concierge | sms | null | `draft` | `known_contacts` | `inbox` |
| concierge | whatsapp | null | `draft` | `known_contacts` | `inbox` |
| concierge | email | null | `draft` | `known_contacts` | `inbox` |

Match key stays `(channel, site_id)` — the tuple of the partial unique — so a
re-run is idempotent even though the owning agent changed. That property was
designed into S26-06 for exactly this case.

`DemoScript` gains a paragraph: the demo now answers a prospect and a tenant
on the same channel, which is the thing to show.

## Acceptance criteria

- [ ] Migration repoints every live binding; second run is a no-op;
      pre-check fails loudly on a duplicate `(channel, site)` pair.
- [ ] One `ai.binding.updated` activity row per repointed binding.
- [ ] `php artisan db:seed` and `demo:seed --fresh` both produce four
      `concierge` rows; re-run idempotent.
- [ ] Feature test: inbound email from an unknown-but-matched prospect on the
      seeded email binding produces a turn, not `skip('agent_ineligible')`.
- [ ] Feature test: same binding, a tenant contact, also produces a turn.
- [ ] Feature test: `audience = existing_tenants` still skips a prospect with
      reason `audience` — the operator control still works.
- [ ] A conversation whose `ai_agent_id` is the archived `sales` row still
      resolves its definition and still evaluates `eligible()`.

## Out of scope

- Removing the `eligible()` gate or the `agent_ineligible` skip reason. Both
  stay for legacy rows; revisit once no live binding points at a legacy agent
  and the skip count has been zero for a quarter.
- Auto-lead capture behaviour (D-AI-20). This task changes who is *eligible*
  for a turn, not how an unmatched sender becomes a contact.
- Per-site bindings. The seeded set stays company-wide; the resolver's
  site-then-company fallback is unchanged.
