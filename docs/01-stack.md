# Tech Stack & Repo Conventions

## Backend — `unit-hq-api/`

- **Laravel 13**, PHP 8.3
- **Auth:** Sanctum
- **DB:** SQLite locally, PostgreSQL in deployment. Single database (mono-tenant).
- **Architecture:** business logic lives in **controllers + models** — there is deliberately **no `app/Services/` layer**. Multi-step operations use explicit DB transactions. Shared billing math / orchestration: `App\Support\Billing\` (`BillingMath`, `ContractBilling`).
- **API response shape** via `ApiResponsable`: `{ message, data }`, paginated responses include `{ meta }`.
- **Tests:** PHPUnit with SQLite in-memory.
- **AI Copilot:** built with the Laravel AI SDK (agent conversations stored in DB).
- **Dev tooling:** Telescope (dev only).

## Frontend — `unit-hq-panel/`

- **Nuxt 4** as SPA — `ssr: false`. Root route redirects to `/leasing/contacts`.
- **Nuxt UI v4** component library, **Tailwind v4**, **Pinia** for state.
- **i18n:** en / es. **All UI strings go through i18n** (`locales/en.json`, `locales/es.json`) — no hardcoded strings.
- **HTTP:** the `useApi()` composable. Types live in `app/types/`.
- **Composable naming:** `useXxx` (single resource) / `useXxxList` (collections).
- **TypeScript rule:** arrays are written as `Array<T>`, not `T[]` (team convention).
- **Package manager:** bun. **CI:** `bun run lint` + `bun run typecheck` only.

## Panel surface (rough page map)

- **Pinned** — **Inbox** (`/inbox`): three-pane threads UI (list / conversation / tenant context) across email, SMS, and calls; triage tab for unmatched inbound; nav badge = unread threads (+ triage indicator)
- **Marketing** — campaigns; templates (email, SMS, WhatsApp)
- **Automations** — automation workflows (filterable by trigger domain)
- **Leasing** — contacts, tasks, deals, offers (+ public offer preview), unit map, reservations, contracts, move-outs
- **Facility** — units, unit classes, rates, discounts, insurance plans, access control
- **Billing** — invoices, payments, overdue, ledger, liens & auctions
- **Insights** — daily-glance dashboard (KPI cards, trends, attention row) plus live reports: rent roll, occupancy (unit/area/economic), ageing, collections, deposit liability, daily close, movement, funnel; CSV export + print
- **Settings** — general, billing settings, payments (legal-entity `payment_provider_accounts`), communications (company provider keys), late fees & liens, tax rates, leasing (`default_esign_expiration_days` among defaults), **Integrations → E-signature** (provider accounts + webhook), custom attributes, object customization, **facility (sites)**, activity log
- **Copilot** — AI conversations (sidebar), separate from Inbox

## Quick start

```bash
# API
composer setup   # install, .env, migrate, vite build
composer dev     # serve + queue + pail + vite

# Panel
bun install && bun run dev   # http://localhost:3000
```
