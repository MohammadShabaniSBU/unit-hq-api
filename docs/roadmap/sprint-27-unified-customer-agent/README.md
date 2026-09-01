# Sprint 27 — Unified customer agent

**Origin:** code review of the two customer-facing definitions against the
dispatch gates and the binding resolver. The sales/support split is enforced
in two places that contradict each other: `AgentChannelBindings::liveRow()`
allows **one** agent per `(channel, site)` (D-AI-19), while
`AgentDefinition::eligible()` splits the audience by tenancy. The result is
that whichever agent holds a binding drops the other half of the traffic.
With the seeded rows, every prospect who emails a site is skipped as
`agent_ineligible` and never answered.

`10-open-decisions.md` already records this under Undecided ("Cross-agent
handoff … deferred"). This sprint closes it by removing the need for routing:
one `ConciergeAgentDefinition` holds the union of both tool surfaces, and
**verification** — not agent identity — decides what a caller can reach.

That change is only safe *and* only useful because of a second finding: no
production principal ever reaches `VerificationLevel::Verified`. It is safe
because every tenant tool (`billing.*`, `contract.summary`, `access.status`)
independently requires `verified` at dispatch gate 3, so the union exposes
nothing new. It is useless without S27-04 because those tools are currently
dead outside `origin = demo`. Merge and verification ship together.

## Findings → tasks

| # | Finding | Evidence | Task |
|---|---|---|---|
| 1 | One agent per `(channel, site)` is structural (`liveRow()` → `first()`, seeder `updateOrCreate` on `(channel, site_id)`), so `eligible()` cannot route — it only drops | `AgentChannelBindings.php:35`, `AgentChannelBindingSeeder` | S27-00, S27-02 |
| 2 | Seeded email binding is support + `existing_tenants`; every prospect email → `skip('agent_ineligible')` | `AgentChannelBindingSeeder`, `RespondWithAgent.php:122` | S27-02 |
| 3 | The stated safety property ("sales lacks billing tools") is gate 1 of nine; gate 3 already requires `verified` for all five tenant tools | `ToolDispatcher::GATE_SEQUENCE`, `*Tool::requiredVerification()` | S27-00 |
| 4 | Nothing writes `verified`: `resolveVerification()` returns anonymous/channel_asserted for non-demo origins, `RespondWithAgent` builds `channelAsserted`, `PrincipalPromotion` stops at channel_asserted. `AgentPrincipal::verified()` is called only by `EvalHarness` | `AgentConversationController:249`, `PrincipalPromotion` docblock | S27-04 |
| 5 | Support's whole distinctive surface is reachable only under `origin = demo` — the demo persona picker is the only writer of `verified` | `AiDemoPersonaController`, `resolveVerification()` | S27-04 |
| 6 | `AgentRegistry::get()` throws on an unknown key and `AiAgent::definition()` does not guard it; `agent_conversations.ai_agent_id` is `restrictOnDelete`, so dropping a definition class 500s every historical conversation | `AgentRegistry.php:26`, `AiAgent.php:68` | S27-00, S27-03 |
| 7 | `agent_write_policies` is unique on `(ai_agent_id, tool_key)`; seven tool keys are held by both agents and need a stated conflict rule | migration, `AiAgentSeeder` | S27-03 |
| 8 | `identityBlock` is prompt part 2 and carries `Today: {date}` + site name, so the cache prefix breaks daily and per site | `AssemblesSystemPrompt::systemPrompt()` | S27-01 |
| 9 | `CassetteKey::schemaHash()` canonicalises the tool list — a union surface invalidates all 57 fixtures' cassettes independently of the prompt | `CassetteKey.php` | S27-01 |
| 10 | `AgentDefinitionCoverageTest::sales_claims_no_verified_tools` asserts the split as a property; it must be replaced, not deleted | `tests/Feature/Ai/AgentDefinitionCoverageTest.php` | S27-00 |

## Sequencing

```
S27-00 ──┬── S27-03 ──── S27-02 ──┐
         │                        ├── S27-05
S27-04 ──┴── S27-01 (re-record) ──┘

S27-06 (last — invariants + docs)
```

S27-00 and S27-04 both change what a prompt and a tool surface look like.
S27-01 is the single re-record window and lands **after both**; recording
twice is the failure mode to avoid. S27-03 (instance rows + policy merge)
must precede S27-02 (binding repoint) so there is a `concierge` row to point
at. S27-05 is panel-only and gates nothing in the API.

## Definition of done for the sprint

One conversation, one thread, both halves:

1. Seed a demo world, bind `concierge` to `email` company-wide with
   `audience = all`, `mode = draft`.
2. Send an inbound email from an address with **no** contact. The agent
   answers with catalogue pricing and captures the lead. No skip.
3. From the same thread, a contact who **is** a tenant asks for their
   balance. The agent requests a verification code, the code arrives on the
   contact's registered channel, the customer returns it, the principal
   promotes to `verified`, and `billing.balance` answers in the next turn.
4. `agent:eval` is green across the migrated fixture suite with zero stale
   cassettes.

Steps 2 and 3 in one thread are the sprint. Either alone is a previous sprint.

## Seeding rule for this sprint

Every seeder change lands in **both** `DatabaseSeeder` and the demo stage
(`Database\Seeders\Demo\StageSeeder` → `php artisan demo:seed --fresh`).
Stage generation performs **no random draws** (`09` code conventions): fixed
arrays, sorted inserts, no `fake()` / `mt_rand()` / `shuffle()`.

## Convention notes for implementers

- No `app/Services/`. Definitions stay under `App\Support\Ai\Agents\`,
  verification under `App\Support\Ai\Identity\`.
- Verification codes are delivered through `SmsSender` / `EmailSender` with a
  `SendContext` of class `transactional` (invariant 38). The agent never
  writes a `messages` row itself.
- Principals are constructed at the boundary and passed down (D-AI-1,
  invariant 56). Promotion is a runtime event, not a boundary re-read.
- `SalesAgentDefinition` and `SupportAgentDefinition` are **retained as
  registered classes forever**. They are historical read paths, not live
  personas. See S27-03.
- Panel: i18n for every string, `Array<T>`, `useApi()`.

## Not in this sprint

- Customer-facing voice. `AgentChannel::Voice` stays out of
  `AgentChannel::bindable()` and `POST /api/agent-conversations` keeps its
  422. Sprint 28.
- AR-03 conversation redaction. Still blocking before any provider binding
  goes to `auto` in production, and S27-04 adds a table to its scope — see
  S27-06.
- Cross-agent handoff. There is no second customer agent to hand off to.
