# AI Assistant Instructions (Cursor / Claude)

## Project in three sentences

Keevaris is a mono-tenant self-storage operations platform: one company, one database, one country per deployment. This repo is the Laravel 13 API. The Nuxt 4 panel and the `keevaris-voice` media bridge are sibling repos; they do not reinterpret these rules.

## Environment and commands

### Backend

- NEVER start, restart, or run the backend yourself — it runs in Docker.
- To check its status: `docker compose ps`
- To run a command inside the backend container, use the alias: `drun <command>`

### Frontend

- Run with `bun run dev`

### Tests

- Run with `docker compose -f docker-compose.test.yml run --rm test`
- Arguments can be passed through, e.g. `docker compose -f docker-compose.test.yml run --rm test path/to/test_file`
- Don't run the full test suite, always try to only test the related tests

### Packages

- NEVER install packages or run any package manager install command.
- If a dependency is missing, stop and tell me which package is needed — I will install it.

## Knowledge map

| Working on… | Read |
|---|---|
| Anything (first time) | `docs/domain/00-overview.md` |
| Repo setup, conventions, panel structure | `docs/domain/01-stack.md` |
| Sites / units / unit classes | `docs/domain/02-facility.md` |
| Prices / rates / tax / insurance / discounts | `docs/domain/03-pricing.md` |
| Contacts / deals / offers / reservations / contracts | `docs/domain/04-crm-pipeline.md` |
| Contract billing, charges, payments, billing periods | `docs/domain/05-billing-ledger.md` |
| Payments, fiscal identity, invoice issuance | `docs/domain/architecture-payments-and-fiscal.md` |
| Messaging, timeline, email blocks, token catalogue | `docs/domain/06-communications.md` |
| Paid advertising | `docs/domain/15-advertising.md` |
| Insights, embedded analytics, reporting schema | `docs/domain/11-insights.md`, `docs/reference/report-definitions.md`, `docs/reference/analytics-schema.md` |
| Automation engine / flow builder | `docs/domain/12-automation-engine.md`, `docs/reference/automation-conditions.md` |
| Playbooks / debt process / lead chase | `docs/domain/13-playbooks.md` (history: `docs/history/README.md`, S09) |
| AI agents, Copilot, tools, guards, write policies, channel bindings | `docs/domain/ai-agents/README.md` |
| Template assistant | `docs/domain/ai-agents/internal-agents.md` |
| Voice media bridge / call path | `keevaris-voice/AGENTS.md`. Delegation and guards stay governed by `docs/reference/09-conventions-and-invariants.md`. Customer voice: `docs/domain/ai-agents/voice.md`. Runtime voice config: `docs/ops/voice-bridge/`. |
| Auth / roles / site selector | `docs/domain/07-people-and-auth.md` |
| Logging / events | `docs/domain/08-activity-logging.md` |
| Always, before committing | `docs/reference/09-conventions-and-invariants.md` |
| Uncertain requirements | `docs/reference/10-open-decisions.md` |
| What is shipped, partial, or still a gap | `docs/STATUS.md` |

## Top non-negotiables

- Mono-tenant: no `company_id` / `tenant_id`, no tenancy package (invariant 1).
- Never update prices, tax rates, or ledger rows in place — new rows or reversals only (invariants 2, 3, 17).
- Never store derived state (availability, balances, overdue) as columns (invariant 5).
- Money is `NUMERIC(10,2)`, never floats; PHP money math goes through `BillingMath` (invariant 10).
- Payment confirmation is rail-specific and never optimistic from the client (invariant 11).
- No `app/Services/` layer; shared helpers live under `App\Support\` (code conventions in 09).
- A customer-facing agent never writes the ledger, mutates a contract, grants access, or confirms a payment (invariant 54a).
- Never grant consent except through `ConsentWriter` (invariant 76).
- Published template content is immutable (invariant 74).
- Never branch on a literal country code outside `App\Support\Country\`. Read country behaviour from `CountryProfiles::current()` (invariant 73).

Full list: `docs/reference/09-conventions-and-invariants.md` — read before committing.

## How work is done here

Tasks live in `docs/tasks/active/`. Read the task file first. A task is not done until the domain docs it affects are updated, and `docs/reference/09-conventions-and-invariants.md` too if an invariant was added or changed. Finished task files move to `docs/history/tasks/`.

## When in doubt

If a request conflicts with an invariant, flag it instead of complying. Uncertain requirements go to `docs/reference/10-open-decisions.md`.

## Related repos

Three git repos sit side by side. This directory is not a monorepo.

| Repo | Role |
|---|---|
| `unit-hq-api` | This API |
| `unit-hq-panel` | Nuxt 4 panel. Its own `AGENTS.md` does not exist yet. |
| `keevaris-voice` | Voice media bridge. Its `AGENTS.md` governs the call path. Delegation and guards stay under `docs/reference/09-conventions-and-invariants.md`. |

There is no shared-type codegen. Types that must match, such as the permission enum, are hand-mirrored and checked by a parity test.
