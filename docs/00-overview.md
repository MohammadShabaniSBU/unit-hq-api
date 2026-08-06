# Keevaris — Project Overview

## What it is

Keevaris is a **mono-tenant** (single-company) self-storage operations platform. It is an all-in-one system for one storage operator — facility management, CRM/leasing pipeline, billing ledger, and Stripe payments scoped to legal entities — intended to replace a pile of separate tools so the operator never needs another application.

> **Tenancy decision (final):** The application is **mono-tenant**. One database, one company. There is **no** `company_id` / `tenant_id` column anywhere, no tenancy package, no per-tenant database routing, and no tenant context in queued jobs. Any earlier notes describing multi-tenancy (database-per-company, tenant-aware migration runners) are superseded.

## Product scope

| Domain | Covers |
|---|---|
| Facility | Sites, unit classes, units, site maps, rates, insurance, discounts |
| CRM / Leasing | Contacts → Deals → Offers → Reservations → Contracts |
| Billing | Charges, payments, invoices, allocations (append-only ledger); contract cadence / tax / deposit at signing |
| Payments | Stripe credentials per legal entity — each entity is the merchant of record (`architecture-payments-and-fiscal.md`) |
| Insights | Operator-ordered registry of native reports and embedded analytics (Metabase / iframe); native vocabulary in `report-definitions.md`, surface in `11-insights.md` |
| Extras | Settings (incl. tax rates, custom attributes, object customization), email templates, automations, AI copilot, tasks/notes |

## Workspace layout

Not a monorepo — two git repos side by side:

| Repo | Role | Stack |
|---|---|---|
| `unit-hq-api/` | Backend API | Laravel 13, PHP 8.3, Sanctum, SQLite (local) / PostgreSQL (deploy) |
| `unit-hq-panel/` | Operator dashboard | Nuxt 4 (SPA, no SSR), Nuxt UI v4, Tailwind v4, Pinia, i18n (en/es), bun |

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

**Always use `Contract` in code.** Domain docs live in this folder (`00`–`11`); there is no separate `product.md` / `erd.md` in-repo.

## Doc set index

- `01-stack.md` — tech stack, repo conventions, quick start
- `02-facility.md` — Site / UnitClass / Unit / rates
- `03-pricing.md` — Price model, tax rates, insurance, discounts
- `04-crm-pipeline.md` — Contact → Deal → Offer → Reservation → Contract
- `05-billing-ledger.md` — Contract billing (cadence/anchor/tax/deposit), charges, payments, invoices, Stripe
- `06-communications.md` — ContactChannel, Interaction, OfferDelivery
- `07-people-and-auth.md` — User / Employee / Contact, roles, site scoping
- `08-activity-logging.md` — activitylog + system_events
- `09-conventions-and-invariants.md` — hard rules; read before writing code
- `10-open-decisions.md` — decided vs undecided vs out of scope
- `11-insights.md` — report registry, analytics accounts, embeds, `analytics` schema contract
- `AGENTS.md` — short index for AI assistants
