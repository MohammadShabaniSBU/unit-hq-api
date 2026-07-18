# unit-hq — Project Overview

## What it is

unit-hq is a **mono-tenant** (single-company) self-storage operations platform. It is an all-in-one system for one storage operator — facility management, CRM/leasing pipeline, billing ledger, and Stripe Connect payments — intended to replace a pile of separate tools so the operator never needs another application.

> **Tenancy decision (final):** The application is **mono-tenant**. One database, one company. There is **no** `company_id` / `tenant_id` column anywhere, no tenancy package, no per-tenant database routing, and no tenant context in queued jobs. Any earlier notes describing multi-tenancy (database-per-company, tenant-aware migration runners) are superseded.

## Product scope

| Domain | Covers |
|---|---|
| Facility | Sites, unit classes, units, site maps, rates, insurance, discounts |
| CRM / Leasing | Contacts → Deals → Offers → Reservations → Contracts |
| Billing | Charges, payments, invoices, allocations (append-only ledger) |
| Payments | Stripe Connect — connected account is the merchant of record |
| Extras | Settings, email templates, automations, AI copilot, dynamic properties, tasks/notes |

## Workspace layout

Not a monorepo — two git repos side by side:

| Repo | Role | Stack |
|---|---|---|
| `unit-hq-api/` | Backend API | Laravel 13, PHP 8.3, Sanctum, SQLite (local) / PostgreSQL (deploy) |
| `unit-hq-panel/` | Operator dashboard | Nuxt 4 (SPA, no SSR), Nuxt UI v4, Tailwind v4, Pinia, i18n (en/es), pnpm |

Deploy: API docker-compose runs PHP-Nginx behind Traefik and joins an external Postgres network.

## The pipeline (spine of the product)

```
Contact → Deal → Offer → OfferOption (selected) → Reservation → Contract
```

## Naming: docs vs code

| Spec / ERD term | Codebase term (canonical) |
|---|---|
| Lease | `Contract` (+ `ContractItem`) |
| Comments | `Notes` (`HasNotes`) |
| Pipeline end "Lease" | `/contracts` API + panel pages |

**Always use `Contract` in code.** Canonical in-repo docs: `unit-hq-api/docs/product.md`, `unit-hq-api/docs/erd.md`.

## Doc set index

- `01-stack.md` — tech stack, repo conventions, quick start
- `02-facility.md` — Site / UnitClass / Unit / rates
- `03-pricing.md` — Price model, immutability, insurance, discounts
- `04-crm-pipeline.md` — Contact → Deal → Offer → Reservation → Contract
- `05-billing-ledger.md` — Charges, payments, allocations, invoices, Stripe
- `06-communications.md` — ContactChannel, Interaction, OfferDelivery
- `07-people-and-auth.md` — User / Employee / Contact, roles, site scoping
- `08-activity-logging.md` — activitylog + system_events
- `09-conventions-and-invariants.md` — hard rules; read before writing code
- `10-open-decisions.md` — what is not decided yet
