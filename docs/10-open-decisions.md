# Open Decisions & Active Work

## Decided (do not reopen)

- **Tenancy: mono-tenant.** Single database, no company scoping. (Supersedes earlier multi-tenant notes.)
- Hierarchy has **no Building layer** — Site → Unit.
- Pipeline entity naming: **Contract**, not Lease.
- Interaction model name (over CommunicationLog / Communication).
- Insurance is **site-scoped** via InsuranceRate.
- Stripe Connect with the company's connected account as merchant of record.

## Undecided

| Topic | Options / notes |
|---|---|
| Revenue model | Application fees on rent vs. flat SaaS subscription vs. both |
| Stripe charge type | Direct vs. destination charges — **tied to the revenue model** |
| Discount model | Next data model to formalise; expresses a reduction, not a standalone amount |
| Jurisdiction rules | Late-fee / lien rules must be configurable per jurisdiction |
| Task reminders | Delivery channel undecided |
| GDPR | Note/comment redaction approach |

## Active WIP (from git status)

- Discounts API + UI
- Contract detail / update
- Contact transactions
- Invoice / payment resources
- Billing seeder
- Reservation → contract conversion paths

Billing / discount / contract UX is actively landing on top of the schema described in these docs.
