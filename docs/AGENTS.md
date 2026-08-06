# AI Assistant Instructions (Cursor / Claude)

You are working on **Keevaris**, a mono-tenant self-storage operations platform (Laravel 13 API + Nuxt 4 panel, two side-by-side repos).

Before writing code, consult the doc that matches the task:

| Working on… | Read |
|---|---|
| Anything (first time) | `00-overview.md` |
| Repo setup, conventions, panel structure | `01-stack.md` |
| Sites / units / unit classes | `02-facility.md` |
| Prices / rates / tax rates / insurance / discounts | `03-pricing.md` |
| Contacts / deals / offers / reservations / contracts | `04-crm-pipeline.md` |
| Contract billing (cadence / anchor / tax / deposit), charges / payments / billing periods | `05-billing-ledger.md` |
| Payments, fiscal identity, invoice issuance (authoritative) | `roadmap/architecture-payments-and-fiscal.md` |
| Messaging / timeline / offer sends | `06-communications.md` |
| Insights, embedded analytics, reporting schema | `11-insights.md` |
| Auth / roles / site selector | `07-people-and-auth.md` |
| Logging / events | `08-activity-logging.md` |
| **Always, before committing** | `09-conventions-and-invariants.md` |
| Uncertain requirements | `10-open-decisions.md` |

## Non-negotiables (summary — full list in 09)

- Mono-tenant: no `company_id`/`tenant_id`, no tenancy package.
- Never update prices, tax rates, or ledger rows in place — new rows / reversals only.
- Never store derived state (availability, balances, overdue) as columns (`billed_through` cursor is the deliberate exception — a date, not money).
- Money is `NUMERIC(10,2)`, never floats; PHP money math via `BillingMath` (bcmath).
- Payment confirmation is rail-specific (Stripe webhook / SEPA at settlement / manual with causer) — never optimistic from the client (invariant 11).
- Payment credentials and fiscal regime scope to `legal_entities`, never to sites, and `legal_entity_id` never scopes a query (invariant 34).
- Entity is `Contract` in code, not Lease.
- Attribute definitions are archive-only (`archived_at`) — never hard-delete. `group_name` on definitions is free-text catalog metadata, **not** an `AttributeGroup` / overview card.
- No `app/Services/` layer; shared helpers under `App\Support\`; transactions for multi-step ops.
- Panel: i18n for all strings; `Array<T>` typing; `useApi()` for HTTP.
- Currency lives on the price row; site and org currency are prefill only; site country is `country_id`, never a denormalised code.
- Embed tokens are minted server-side only; dynamic params are always locked.

If a request conflicts with `09-conventions-and-invariants.md`, flag the conflict instead of silently complying.
