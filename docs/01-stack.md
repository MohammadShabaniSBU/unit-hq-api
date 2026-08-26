# Tech Stack & Repo Conventions

## Backend — `unit-hq-api/`

- **Laravel 13**, PHP 8.3
- **Auth:** Sanctum
- **DB:** SQLite locally, PostgreSQL in deployment. Single database (mono-tenant).
- **Architecture:** business logic lives in **controllers + models** — there is deliberately **no `app/Services/` layer**. Multi-step operations use explicit DB transactions. Shared billing math / orchestration: `App\Support\Billing\` (`BillingMath`, `ContractBilling`).
- **API response shape** via `ApiResponsable`: `{ message, data }`, paginated responses include `{ meta }`.
- **Tests:** PHPUnit with SQLite in-memory.
- **AI:** internal Copilot plus customer-facing agents (support / sales) on a
  shared tool-and-guardrail runtime in `App\Support\Ai\`. Conversations and
  traces stored in DB. Sales may persist Offer and Reservation under
  `agent_write_policies` (invariant 54b). Agents answer real inbound email /
  SMS / WhatsApp under a live `agent_channel_bindings` row (default off,
  invariant 68); replies go out through the channel senders (invariant 69).
  Demo surface at panel `/demo/chat`, gated by `agents.demo_enabled`.
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
- **Marketing** — campaigns; templates (email, SMS, WhatsApp). The email builder (`app/components/email-builder/`) doubles as a document/contract-authoring tool: alongside the usual email blocks it has a `document` template channel and contract-specific blocks (`PartiesBlock`, `TermsTableBlock`, `SignatureAnchorBlock`, `LegalSectionBlock`)
- **Automations** — automation engine workflows (list + `[id]` flow-canvas graph editor built on `@vue-flow/core`, filterable by trigger domain) and `[id]/runs` run history/detail — see `12-automation-engine.md`
- **Playbooks** — linear, kind-typed enrolment sequences (debt process / lead chase) that compile onto the automation engine — builder, enrolments, kind landing pages — see `13-playbooks.md`
- **Leasing** — contacts, tasks, deals, offers (+ public offer preview with localized discount promo lines), unit map, reservations, contracts (billing card: discount chip / schedule / remove), move-outs; walk-in + convert pick a catalogue discount; contact/deal detail overview shows an operator-triggered AI summary card; **agent approvals** (`/leasing/agent-approvals`) queue for propose-mode writes
- **Facility** — units, unit classes, rates, discounts, insurance plans, access control
- **Billing** — invoices, payments, overdue, ledger, liens & auctions
- **Insights** — registry-driven nav (`insight_reports`): native reports and embedded analytics (Metabase / iframe); order and visibility from Settings → Insights
- **Settings** — general, billing settings, payments (legal-entity `payment_provider_accounts`), communications (company provider keys), **Insights** (analytics connections + report builder), late fees & liens, tax rates, leasing (`default_esign_expiration_days` among defaults), **AI agents** (`/settings/ai-agents`, write policies), **Integrations → E-signature** (provider accounts + webhook), custom attributes, object customization, **facility (sites + discounts catalogue)**, activity log
- **Copilot** — AI conversations (sidebar), separate from Inbox
- **Demo** — `/demo/chat`: agent console (agent / channel / persona /
  verification pickers, channel-skinned conversation, tool-and-guardrail trace).
  Flag-gated; not an operator surface.

## Quick start

```bash
# API
composer setup   # install, .env, migrate, vite build
composer dev     # serve + queue + pail + vite
php artisan demo:seed --fresh   # optional living demo world (+ storage/demo-script.md)

# Panel
bun install && bun run dev   # http://localhost:3000
```

See also root `README.md` § Demo world and `docs/roadmap/seeders/`.
