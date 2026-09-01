# S27-00 — `ConciergeAgentDefinition`: one customer agent, verification-scoped

**Depends on:** nothing
**Blocks:** S27-01, S27-03
**Touches:** `unit-hq-api`

## Problem

`SalesAgentDefinition` and `SupportAgentDefinition` split the customer
audience by tenancy through `eligible()`. That split cannot route, because
`AgentChannelBindings::liveRow()` returns exactly one agent per
`(channel, site)` (D-AI-19). Whichever agent holds the binding answers its
half and calls `skip($message, 'agent_ineligible')` on the other. With the
seeded rows that is every prospect who emails a site.

The safety argument for keeping them apart does not hold. `toolKeys()` is
dispatch gate 1 of nine; gate 3 is `verification`, and all five tenant tools
already return `VerificationLevel::Verified` from
`requiredVerification()` — `AccessStatusTool`, `BillingBalanceTool`,
`BillingInvoicesTool`, `BillingNextChargeTool`, `ContractSummaryTool`. An
agent holding the union of both lists exposes nothing to an unverified
caller that the split was preventing.

## What to build

### `App\Support\Ai\Agents\ConciergeAgentDefinition`

`key()` → `concierge`. Uses `AssemblesSystemPrompt` like its predecessors.

**`toolKeys()`** — the union, in a stable order (the order feeds
`schemaHash()`; churn there costs a re-record):

```
facility.availability, facility.find_sites, facility.site_info,
facility.size_guide, calendar.resolve, pricing.quote, pricing.discounts,
sales.propose_offer, sales.create_offer, sales.create_reservation,
crm.create_contact, crm.create_deal, crm.create_task, crm.create_note,
contract.summary, billing.balance, billing.next_charge, billing.invoices,
access.status, kb.faq_lookup, agent.escalate
```

`identity.request_code` and `identity.verify_code` are appended by S27-04.

**`eligible()`** — returns `true`, always. Eligibility stops being a routing
mechanism; `agent_channel_bindings.audience` keeps that job (S27-02).
`AgentEligibility::hasInForceContractAtSite()` is **retained** — it is still
the implementation of `BindingAudience::ExistingTenants` in
`RespondWithAgent::audienceAllows()`.

**`handoffRules()`** — `[]`. Both predecessors returned `[]` and relied on
the shared `config('ai-handoff.rules.*')` set; nothing to merge.

**`forbiddenClaims()`** — union of both (both are `[]` today; keep the
override so the union is explicit if either grows).

### Verification-branched role paragraph

`roleParagraph(AgentContext $ctx)` branches on
`$ctx->principal->verification`:

| Level | Paragraph |
|---|---|
| `anonymous`, `channel_asserted` | The current sales paragraph, verbatim, plus one sentence: an existing customer asking about their own account must be offered verification before any account question is answered. |
| `verified` | The current support paragraph, verbatim, plus the sales quoting rules — a verified tenant asking about a second unit is the common case the split could not serve. |

Do **not** concatenate both paragraphs at every level. An unverified caller
should not be told that balance and invoice tools exist; that is a prompt-
disclosure decision, not a safety one, and it keeps the anonymous prompt at
roughly its current length.

`promptVersion()` already hashes `roleParagraph()` and excludes
`identityBlock` / `disclosureBlock`, so each branch is its own prompt version
and each gets its own cassettes. That is intended.

### Registration

In `AppServiceProvider` the `AgentRegistry` singleton registers **three**
definitions:

```php
$registry->register(new SupportAgentDefinition);   // legacy — historical rows
$registry->register(new SalesAgentDefinition);     // legacy — historical rows
$registry->register(new ConciergeAgentDefinition);
```

`SalesAgentDefinition` and `SupportAgentDefinition` are never deleted.
`AiAgent::definition()` calls `AgentRegistry::get()`, which throws
`RuntimeException` on an unknown key and is not guarded at the call site;
`agent_conversations.ai_agent_id` is `restrictOnDelete`, so every historical
conversation resolves its definition on read. Removing either class 500s the
Inbox conversation list. Add a class-level docblock on both saying so.

### Test changes

`AgentDefinitionCoverageTest::sales_claims_no_verified_tools` asserts the old
split as a property. Replace it — do not delete it — with two assertions
that state the new property:

- `concierge_verified_tools_are_gated`: for every tool in
  `ConciergeAgentDefinition::toolKeys()` whose `requiredVerification()` is
  `Verified`, dispatching it with a `channel_asserted` principal returns
  `ToolResult::denied(ToolDeniedReason::Verification)`. This is the
  assertion that carries the safety argument, and it tests the gate rather
  than the list.
- `legacy_definitions_stay_registered`: `sales` and `support` both resolve
  from the registry, so an archived `ai_agents` row still renders.

`every_seeded_agent_key_resolves_and_tool_keys_are_registered` needs no
change; it iterates `AiAgent::pluck('key')` and will pick up `concierge`
once S27-03 seeds it.

## Acceptance criteria

- [ ] `ConciergeAgentDefinition` registered; `AgentRegistry::all()` returns
      three definitions.
- [ ] `toolKeys()` contains all 21 keys, each registered in `ToolRegistry`.
- [ ] `eligible()` returns true for a null contact, a prospect, and a tenant.
- [ ] Role paragraph differs across the three verification levels;
      `promptVersion()` differs across them too.
- [ ] `concierge_verified_tools_are_gated` passes for all five tenant tools.
- [ ] `legacy_definitions_stay_registered` passes.
- [ ] No production code path constructs `SalesAgentDefinition` or
      `SupportAgentDefinition` other than the registry.

Introduces **D-AI-22** and **invariant 71** (S27-06).

## Out of scope

- Seeding the `ai_agents` row and repointing bindings — S27-03, S27-02.
- Re-recording cassettes — S27-01. This task will leave `agent:eval` red for
  every fixture; that is expected and is why S27-01 exists.
- Any change to `ToolDispatcher`. The gates are correct as they stand; this
  sprint relies on them rather than modifying them.
