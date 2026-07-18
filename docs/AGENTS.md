# AI Assistant Instructions (Cursor / Claude)

You are working on **unit-hq**, a mono-tenant self-storage operations platform (Laravel 13 API + Nuxt 4 panel, two side-by-side repos).

Before writing code, consult the doc that matches the task:

| Working on… | Read |
|---|---|
| Anything (first time) | `00-overview.md` |
| Repo setup, conventions, panel structure | `01-stack.md` |
| Sites / units / unit classes | `02-facility.md` |
| Prices / rates / insurance / discounts | `03-pricing.md` |
| Contacts / deals / offers / reservations / contracts | `04-crm-pipeline.md` |
| Charges / payments / invoices / Stripe | `05-billing-ledger.md` |
| Messaging / timeline / offer sends | `06-communications.md` |
| Auth / roles / site selector | `07-people-and-auth.md` |
| Logging / events | `08-activity-logging.md` |
| **Always, before committing** | `09-conventions-and-invariants.md` |
| Uncertain requirements | `10-open-decisions.md` |

## Non-negotiables (summary — full list in 09)

- Mono-tenant: no `company_id`/`tenant_id`, no tenancy package.
- Never update prices or ledger rows in place — new rows / reversals only.
- Never store derived state (availability, balances, overdue) as columns.
- Money is `NUMERIC(10,2)`, never floats.
- Payments confirmed only via Stripe webhooks + idempotency keys.
- Entity is `Contract` in code, not Lease.
- No `app/Services/` layer; transactions for multi-step ops.
- Panel: i18n for all strings; `Array<T>` typing; `useApi()` for HTTP.

If a request conflicts with `09-conventions-and-invariants.md`, flag the conflict instead of silently complying.
