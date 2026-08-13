# Open Decisions & Active Work

## Decided (do not reopen)

- **RBAC: no `spatie/laravel-permission`.** It solves roles↔permissions cheaply but its `teams` answer to site scoping is a request-global current-team context — the ambient scoping shape invariants 1 and 34 forbid. Permission names appear inside policy methods, so they are code; a PHP enum (`App\Support\Auth\Permission`) gives grep-as-test. Grants live in `roles` / `role_permissions` / `employee_roles` (S17-01).
- **D-RBAC-1 — Contact / Deal list visibility.** A Contact is not site-scoped as a subject (`SubjectSite` → null), and detail views still show full cross-site activity. Lists cannot show every site's roster: a site-scoped agent sees contacts with at least one relation (`contact_sites`, deal, reservation, contract, or message thread) to a granted site, **plus** contacts with no site relation at all (unassigned leads — no `contact_sites` and no other site path). Once a contact is opened, detail is unchanged. Deals follow the same rule: `site_id` in granted sites or `site_id IS NULL`.
- **`Employee` is not renamed to `Member`.** Measured blast radius: 24 migrations reference `employees`, ~50 FK declarations, 1,124 mentions across 232 files in `app/` / `database/` / `tests/` / `routes/`. Zero user-visible benefit; “member” is the word operators apply to the *tenant*. External accountants get a **role**, not a class rename.
- **No `employee` morph-map alias.** Adding `'employee' => Employee::class` would write new `activity_log.causer_type` as `employee` while historical rows keep the FQCN; invariant 15 forbids backfilling append-only history, so filters would need both forever. Laravel resolves the FQCN via its fallback; omit the alias deliberately.
- **Tenancy: mono-tenant.** Single database, no company scoping. (Supersedes earlier multi-tenant notes.)
- Hierarchy has **no Building layer** — Site → Unit.
- Pipeline entity naming: **Contract**, not Lease.
- Interaction model name (over CommunicationLog / Communication).
- Insurance is **site-scoped** via InsuranceRate.
- **Stripe: per legal entity.** Each `legal_entity` holds its own Stripe credentials via `payment_provider_accounts` and is merchant of record. No Connect, no platform account, no application fees, no `mode` column. Webhook verification is per payment-provider-account (crypto-random `account_token` + signing secret), never by PK. Full model: `architecture-payments-and-fiscal.md`.
- **Comms + payment credential rules are shared**: mask as `••••••` + last 4, blank submitted field = unchanged, `encrypted` cast, Tier-3 `RecordsActivity::core` on create/rotate/remove (never the secret), `DecryptException` → `credentials_unreadable` instead of a 500. Applies to `communication_accounts` and `payment_provider_accounts`. Archived entities / inactive accounts ack-and-ignore inbound webhooks. Shared helpers: `App\Support\Credentials\`.
- **GDPR activity/system_events redaction:** `contacts:redact {contact}` nulls an allowlisted set of JSON keys in `activity_log.properties` and surviving `system_events.payload` for rows whose subject is that contact, then inserts a tier-3 `contact.redacted` event (fact only — never what was removed). Config: `config/redaction.php`.
- **Contract billing model (contract / contract_item / tax_rate enhancement) — shipped:**
  - **Tax basis: exclusive.** Item `amount` is net; `tax = round(net × rate/100, 2)`; `gross = net + tax`. Catalogue: immutable `tax_rates` versions by `code`; products hold `tax_rate_code`; items/charges snapshot id + %.
  - **Billing timing: in advance.** First charge `due_date` = move-in.
  - **Default proration: `daily`** (org setting; snapshotted per contract at signing). Alternatives: `full_period`, `none`.
  - **Billing anchor: selectable per org**, snapshotted per contract —
    - `anniversary` — anchor = move-in; every period full; no stub
    - `calendar` — fixed day-of-month (`billing_anchor_day` 1..28); requires monthly cadence; off-boundary move-in → stub
    - `calendar_week` — fixed ISO weekday (`billing_anchor_day` 1=Mon..7=Sun); requires weekly cadence; off-boundary move-in → stub
  - **Proration basis: calendar-days** — divide by the real day-length of the containing period, never a nominal 30. Arithmetic is `bcmath` (`BillingMath`), multiply-before-divide at high intermediate scale, single half-up round at the end.
  - **Cadence has no per-contract override in v1** — every contract inherits the org's billing interval/count at signing.
  - **`billed_through` is a stored cursor**, not derived from invoices and never cached money.
  - **Deposit:** optional charge at create when `deposit_amount > 0`; not revenue; no refund lifecycle in v1.
  - **First-period charges** written in the same transaction as contract create / reservation convert; convert-preview shares `ContractBilling` / `BillingMath`.
  - Detail: `05-billing-ledger.md`, `03-pricing.md`, `09-conventions-and-invariants.md`.
- **Object customization activity logging:**
  - Attribute value upserts/clears → Tier-2 on the **parent entity**, channel via `AttributeEntityType::activityChannel()`.
  - Layout group/field mutations → Tier-2 `facility` (`layout.group.*` / `layout.field.*`).
  - Definition lifecycle → Tier-2 `attribute.definition.created` / `.updated` / `.archived` / `.unarchived` (no `.deleted`).
- **Default overview layouts** are inserted by `DefaultAttributeLayoutSeeder` (system cards + native `layout_fields`). Custom-attribute definition seeding remains deferred.
- **Attribute definitions are archive-only** — `archived_at`; `POST …/archive` / `…/unarchive`; list `?status=active|archived|all`. No hard delete.
- **`attribute_definitions.group_name` ≠ layout cards** — free-text catalog metadata only; overview cards are `attribute_groups` via `layout_fields`. Do not connect the Custom attributes form group field to `AttributeGroup`.
- **Automation engine (v1):** `App\Support\Automation\`; triggers `object_created`/`object_updated`/`schedule`; actions `update_object`/`create_object`/`send_email`; `logic.branch`. Hard loop suppress via `AutomationContext`. Bulk graph PATCH kept. `trigger.email_received` type exists but activation blocked until inbound webhook ships.
- **`action.create_object` v1:** allowlist = Contact, Deal, Task, Note. Field values reuse `ValueSource` (`static` / `dynamic`) matching `update_object` — not TargetRecord-style per-field modes. Created record emits `subject_id` for downstream `step_output` targeting. Task/Note use `relatedTo` (TargetRecord, default `trigger_subject`) for the parent morph; `created_by` / `employee_id` default from run causer or first employee.
- **D1 — Currency lives on the price row.** `prices.currency` is sole authority. Site and org currency are form prefill only. Contract snapshots amount **and** currency. Supersedes roadmap README §4 item 1.
- **D2 — Tax jurisdiction vocabulary.** `tax_rates.jurisdiction` is `NULL` or ISO 3166-1 alpha-2 with optional ISO 3166-2 subdivision. Site country is `country_id` → `countries.code` (no denormalised `country_code`). Jurisdiction-aware resolution is live via `TaxResolver` (S03-05): exact country match → NULL → loud 422.
- **D3 — Charge types.** `adjustment`, `write_off`, `refund` added to `ChargeType` with `isRevenue()` excluding `deposit` / `write_off` / `refund`. Revenue grouped by currency via `App\Support\Billing\RevenueByCurrency`. A refund does not reverse revenue; credit notes are S03.
- **D4 — Panel locale `fr`.** Scaffolded alongside `en`/`es`. Locale never determines currency.
- **D5 — Invoice vs Statement.** Fiscal **Invoice** (S03); **Statement** is a computed view. Current display-grouping table renamed to `billing_periods` (S01-00b).
- **Rate junction currency mismatch (S01-00b).** Attaching a price whose currency differs from `sites.currency` (when set) hard-fails with 422 unless the request passes `allow_currency_mismatch=true`. Recorded so a wrong denomination cannot silently land on a rate.
- **D6 / D7 — Payments and fiscal identity scope to `legal_entities`.** Not sites. Sites belong to one legal entity. `legal_entities` is fiscal, not a tenancy boundary (invariant 34). Supersedes roadmap README §4 D6/D7 site scoping. Schema in S03; doc surgery in S01-05.
- **D-entry — S03 built answer-agnostic.** Schema, Settings UI, and issuance path support several legal entities; one is seeded (`PENDING-GESTOR`). The client's one-vs-several answer is go-live data entry, not a code blocker. See sprint-03 README.
- **D8 — Date boundaries via site timezone.** `App\Support\Time\SiteClock`. Bare `Carbon::today()` / `->toDateString()` on timestamps are defects.
- **Automation `waiting` run status (S08-00):** `AutomationRunStatus::Waiting` parks `logic.wait` with `waiting_until` + `current_node_id` cursor. Resume via delayed `ResumeAutomationRun` and authoritative `automations:resume-waiting` sweeper. Cancel + run-level guard funnel through `RunLifecycle`. Business-hours/weekday wait windows deferred to S09 playbook params if needed.
- **Demo baseline:** the living facility for demos and cross-surface consistency is `php artisan demo:seed` (optional `--fresh`) — simulated history through real jobs/injectors, not staged status rows. Deterministic via `DEMO_SEED` (crowd only; cast fixed). Presenter sheet: `storage/demo-script.md`. Per-sprint `DatabaseSeeder` fixtures remain test-only and must stay independent (`docs/roadmap/seeders/`).
- **D-DISC — Discount catalogue & compile-at-signing (ratified 2026-08-02).**
  1. **No clawback in v1.** Early leavers keep free weeks; clawback-as-vacate-settlement-charge is the recorded follow-up.
  2. **Zero-total periods: charge yes, invoice no.** €0 charges write (ledger continuity); invoice issuance skips zero-total periods (gestor-flippable flag).
  3. **Percent tracks rate changes** — scheduled increase recomputes `new list × (1−p)`. Plus a remove action that versions back to list at the next period boundary (Tier-3 with reason).
  4. **Unit items only in v1** (insurance untouched). Catalogue is archive-only `percent` / `free_time` rows operators pick; they compile into contract-scoped item versions at signing so billing never interprets discounts (invariant 41). Detail: `03-pricing.md`, sprint-17.
- **I1 — One registry, two sources.** Native reports and embedded reports live in the same `insight_reports` table, distinguished by `source`. The Insights nav is one ordered, operator-controlled list. There is no "integrations" sub-menu and no second nav section. **Rationale:** an operator who replaces our ageing report with their own should be able to hide ours and put theirs in the same slot. Two lists make that impossible and make the nav order a code change.
- **I2 — Dynamic params are always locked.** `value_source = 'dynamic'` implies `binding = 'locked'`. Enforced by a `CHECK` constraint, not by application code alone. Only `static` params may be `default` (an editable filter widget seeded with a value). **Rationale:** dynamic values are site scope and viewer identity. An editable dynamic param is a URL-editing privilege escalation. There is no legitimate case for one; if a viewer should be able to change a value, it is not a scope value.
- **I3 — Dynamic values come from a whitelist.** Resolvers live in `App\Support\Insights\DynamicParams` and are addressed by a stable key. The stored `dynamic_key` is validated against the registry on write and on read. No free text, no expression language, no reuse of the automation `ValueSource` *expression* strings. **Rationale:** same reasoning as `FilterableFields` — never trust a client-supplied identifier that resolves to data access. The automation engine's dynamic expressions are richer than we need and carry a much larger attack surface. Deliberate half-borrow: reuse the **`static` / `dynamic` vocabulary** from `action.create_object` so the panel component and the operator's mental model are the same, but **not** the expression grammar.
- **I4 — Operator labels are per-locale JSONB; native labels are i18n keys.** `insight_reports.labels` is `JSONB` keyed by locale, populated only for operator-created reports. Native rows leave it `NULL` and resolve through `native_key` → `en.json` / `es.json` / `fr.json`. One resolver handles both: `label = labels->>locale ?? labels->>'en' ?? t("insights.reports.{native_key}.label")`. **Rationale:** operator data cannot go through the locale files (they are shipped assets), and native strings must not be duplicated into the database (they would drift from the translations). Trying to make one mechanism serve both produces either untranslatable built-ins or unsyncable operator strings.
- **I5 — `visibility` ships as an enum stub now.** `insight_reports.visibility`: `all` | `company_only` | `site_staff`. Only `all` is honoured this sprint; the others are accepted, stored, and ignored until RBAC lands (S17 / `canEdit` stopgap). The column and enum exist now. **Rationale:** exactly the D3 reasoning — adding enum values later means backfilling. A column nobody reads costs nothing.
- **I6 — Site scope is per-report, not global.** `insight_reports.site_scope_mode`: `inherit` (default) | `ignore`. `inherit` — the global site selector feeds `current_site_id` / `visible_site_ids`, and the report re-renders when the selector changes. `ignore` — the report is rendered once, unscoped; the panel hides the site selector's effect on it and labels the report as company-wide. **Rationale:** a customer's own corporate dashboard often has no concept of our sites. Forcing `inherit` means either a broken filter or a lie in the UI.
- **I-Metabase — Ship OSS with the banner.** Metabase OSS shows a "Powered by Metabase" banner on embeds and does not allow appearance customisation. We ship / document OSS Metabase with that banner visible; appearance customisation is out of scope for our packaging. **Do not attempt to hide the banner with CSS or iframe tricks** — it is a licence term. If a customer brings their own instance, **their plan is not our problem** — we mint/render whatever their instance returns and say so in the docs. Paid white-label is a customer procurement choice, not something we bundle into Keevaris pricing in this sprint. Detail: `roadmap/sprint-21-insights-pluggable-surface/`.

## Explicitly out of scope (for now)

- Deposit refund/deduction **payout execution** (moving money) — the decision/recording half (`DepositSettlement` at vacate) is shipped; only executing the actual payout remains deferred.
- Per-contract cadence override.
- Calendar multi-period epoch for `interval_count > 1` (still one boundary per month-day / weekday).
- Stripe PaymentIntent wiring for deposits / first period (same payout-execution gap as the deposit line above — `payout_status` stays `pending` until this lands).
- Custom-attribute **saved views** and column promotion (advanced filters / `POST …/search` shipped; snapshots deferred).
- Object-customization drag-and-drop reorder (arrow reorder ships first); multi-column / conditional / per-role layouts.
- Removing or renaming `attribute_definitions.group_name` (catalog metadata unused by layout; kept for now).
- Automation edge fan-out (one edge per `(automation_id, source_node_id, source_handle)` — unique constraint). Multi-target fan-out deferred.
- Inbound email → `trigger.email_received` webhook handler.
- Automation TokenPicker UI (content-field token insertion). Run-log panel shipped.
- **`action.create_object` for Contract / Reservation / Offer** — creation is not a plain insert (billing transaction, offer-acceptance atomicity, offer token/status flow). Needs dedicated nodes calling real transactional creation paths (`ContractBilling`, offer acceptance), not the generic handler.
- **FK-shaped create/update field pickers** — later UI polish: TargetRecordPicker-style dropdown for FK fields that still stores a dynamic expression string underneath.
- Writing to a customer's analytics instance (publishing dashboards, flipping embed params).
- A generic expression language for dynamic insight params.
- Cross-report filtering, favourites, dashboards-of-dashboards.
- Tenant-facing analytics of any kind (contacts still do not log in).

## Gestor confirmations (needed before S04 ends, not before S03 starts)

| # | Question | Affects |
|---|---|---|
| 1 | One entity or several? NIF(s), registered addresses, SEPA creditor IDs | Go-live data entry |
| 2 | May self-storage rents be invoiced as **facturas simplificadas**, or do amounts/activity require ordinary invoices with tenant NIF? | Whether tenant tax IDs must be collected at signing (S03-01) |
| 3 | Are **deposit** charges excluded from VAT invoices (guarantee, not supply)? Default here: excluded | S03-03 issuance filter |
| 4 | Rectificative method to state on credit invoices (por diferencias assumed) | S03-04 wording |
| 5 | Series numbering across the SpaceManager cutover — continue or fresh series at genesis? | Go-live series setup |

## Undecided

| Topic | Options / notes |
|---|---|
| Revenue model | **Flat SaaS** (default direction). Application fees are off the table without Connect; do not reopen destination charges / platform fee collection. |
| Jurisdiction rules | **Addressed (S07-00):** site-assigned `delinquency_policies` (ES/UK flavours seeded). Per-contract override deferred (site-level is v1). |
| Delinquency per-contract override | Site-level policy assignment only in v1. Per-contract override deferred. |
| Delinquency recurring steps | One-shot per case in v1; repeating/recurring ladder steps deferred. |
| Late-fee fee-terms snapshot | Live-policy reading in v1 (see `09` invariant 18 exception). Snapshotting fee type/amount/percent onto contracts is the follow-up if a client pins exact fees in contract terms. |
| Task reminders | Delivery channel undecided |
| GDPR | Note/comment redaction approach (activity log redaction decided above) |
| Agent conversation redaction | Copilot / `agent_conversation_messages` hold contact names, emails and balances. `ai_summaries` **is** covered by `contacts:redact` from S22 (null `body` / `highlights` / `source_counts` on the contact and its deals). Extending redaction to conversation message tables remains the open gap (AR-03 out of scope). |
| Playbook payment links | Debt playbook emails may reference balance / a pay-link *placeholder*; auto-creating a payment request per enrolment is an **S10-era action**, not S09. |
| Multi-playbook debt routing | v1 rejects overlapping active debt playbooks for the same site-filter coverage (empty `site_ids` = all sites). Richer priority / routing across overlapping site sets deferred. |
| Pre-signature deposits / holding fees | S14-00 deliberately does not support taking a deposit (or any payment) before remote signature completes. An `awaiting_signature` contract has zero ledger rows; cancel leaves no fiscal trace. Holding fees / pre-signature deposits are a known future ask. |
| Document co-signers | S14-01 v1 requires exactly one `signature_anchor`. Multi-party / co-signer anchors deferred. |
| Clause libraries / conditional sections | S14-01 ships a fixed block vocabulary (`legal_section`, smart blocks). Reusable clause libraries and conditional sections are deferred. |
| Notices / vacate via document channel | S14-01 is contract-purpose only. `contract_notices.document_ref` (S02) pairing with the document channel is the natural fast-follow, not built here. |
| Per-entity e-sign accounts | S14-02 v1: one active e-sign provider account per install. Per-legal-entity signing brand deferred (unlike payments). |
| Multi-door units | S15 v1: one live `unit_door` access point per unit (partial unique). Multi-door / multi-zone per unit deferred. |
| Visitor / temporary access | S15 v1 grants follow occupancy + standing only. Short-lived visitor credentials deferred (model can grow via short-lived desired-grants later). |
| Per-point access suspension | S15 suspensions are contract-total by design. Per-point suspension (deny door, keep gate) deferred. |
| After-hours / schedule rules | Schedule-based access restrictions are provider-side territory for now; not modeled in desired-state v1. |
| Drift incident dismiss | S15-04 stores denied-but-granted incidents on `sync_attention` for 30 days for contracts-index chips; no operator dismiss API yet. |
| Report nightly snapshots | **Escape hatch only.** Insights figures are live bounded queries from fact tables (S16 harvest principle). Nightly snapshot / rollup tables are sanctioned **only if** a report proves slow at real operator scale — design the cache then; do not invent rollups preemptively. Vocabulary: `docs/report-definitions.md`. |
| Configurable Insights dashboards | **Report registry / nav superseded by S21 (I1)** — native and embedded reports share one operator-ordered `insight_reports` list. S16-04's fixed daily-glance layout (KPI cards + two trends + attention) and per-employee drag-and-drop widgets remain deferred. |
| Scheduled email reports | S16 does not email report PDFs/CSVs on a cadence. Natural later playbook action once Insights figures are trusted. |
| Panel idle timeout | S17-07 deliberately ships no idle session timeout. Shared front-desk machines rely on staff locking the workstation. Options later: soft idle warning + re-auth, hard logout after N minutes, or leave as OS-lock-only. |
| `site_map_shapes` join table | Resolve SVG shapes into a join table at upload so map endpoints stop re-parsing XML and renames surface instead of vanishing. Deferred from S20 — matching stays a computed `id_match` attribute for now. |
| Report-level permissions | `visibility` enum ships as a stub; only `all` is honoured. Real enforcement lands with RBAC through the same helper as `canEdit`. |
| Currency conversion in reporting | `analytics` views expose `currency` and never sum across it. A conversion layer needs an FX rate source and a policy on which date's rate applies — not started. |
| Additional `analytics` views | Demand-driven. Five ship with the Insights pluggable surface; there is no target catalogue. |
| Scheduled report delivery | Metabase has subscriptions; do we surface them, or build our own on the comms stack? Duplicating delivery is how two unsubscribe lists appear. |
| Per-report caching | Provider-side caching only for now. |

## Active WIP

- Discount compiler / signing integration + attach surfaces (sprint-17 DISC-01…03; catalogue DISC-00 shipped)
- Contract detail / update surfaces
- Contact transactions
- Invoice / payment resources polish
- **S10 schema defect (fixed in S11-01):** `message_attachments.message_id` is nullable so outbound compose can stage files before send; orphans are swept daily. Not an S11 schema expansion.
