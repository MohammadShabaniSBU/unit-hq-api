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
- **Package manager:** pnpm. **CI:** `pnpm lint` + `pnpm typecheck` only.

## Panel surface (rough page map)

- **Leasing** — contacts, deals, offers (+ public offer preview), reservations, contracts, unit map, insurances
- **Facility** — sites, units, unit classes, rates, discounts, insurance plans
- **Billing** — invoices, payments, ledger, overdue
- **Marketing** — email builder, automations, campaigns
- **Analytics** — occupancy, conversion, revenue
- **Settings** — general, billing, tax rates, leasing, activity log, Stripe Connect
- **Inbox / Copilot** — messaging + AI conversations

## Quick start

```bash
# API
composer setup   # install, .env, migrate, vite build
composer dev     # serve + queue + pail + vite

# Panel
pnpm install && pnpm dev   # http://localhost:3000
```
