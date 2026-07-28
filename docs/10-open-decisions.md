# Open Decisions & Active Work

## Decided (do not reopen)

- **Tenancy: mono-tenant.** Single database, no company scoping. (Supersedes earlier multi-tenant notes.)
- Hierarchy has **no Building layer** — Site → Unit.
- Pipeline entity naming: **Contract**, not Lease.
- Interaction model name (over CommunicationLog / Communication).
- Insurance is **site-scoped** via InsuranceRate.
- **Stripe: per-site direct keys** — each site is merchant of record; no Connect; no application fees; no `mode` column. Webhook verification is site-scoped (`site_stripe_settings.webhook_route_token` + `webhook_secret`). Full flow: `05-billing-ledger.md`.
- **Comms + Stripe credential rules are shared**: mask as `••••••` + last 4, blank submitted field = unchanged, `encrypted` cast, Tier-3 `RecordsActivity::core` on create/rotate/remove (never the secret), `DecryptException` → `credentials_unreadable` instead of a 500, archived sites keep credentials but stop sending / ignore inbound webhooks. Shared helpers: `App\Support\Credentials\`.
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

## Explicitly out of scope (for now)

- Discount snapshot JSON / full discount-on-contract-item model (discount columns exist; formal model still open below).
- Deposit refund / deduction lifecycle.
- Recurring billing job beyond first-charge generation (cursor is ready; job is not).
- Per-contract cadence override.
- Calendar multi-period epoch for `interval_count > 1` (still one boundary per month-day / weekday).
- Stripe PaymentIntent wiring for deposits / first period.
- Custom-attribute **saved views** and column promotion (advanced filters / `POST …/search` shipped; snapshots deferred).
- Object-customization drag-and-drop reorder (arrow reorder ships first); multi-column / conditional / per-role layouts.
- Removing or renaming `attribute_definitions.group_name` (catalog metadata unused by layout; kept for now).
- Automation edge fan-out (one edge per `(automation_id, source_node_id, source_handle)` — unique constraint). Multi-target fan-out deferred.
- Inbound email → `trigger.email_received` webhook handler.
- Automation TokenPicker UI (content-field token insertion). Run-log panel shipped.
- **`action.create_object` for Contract / Reservation / Offer** — creation is not a plain insert (billing transaction, offer-acceptance atomicity, offer token/status flow). Needs dedicated nodes calling real transactional creation paths (`ContractBilling`, offer acceptance), not the generic handler.
- **FK-shaped create/update field pickers** — later UI polish: TargetRecordPicker-style dropdown for FK fields that still stores a dynamic expression string underneath.

## Undecided

| Topic | Options / notes |
|---|---|
| Revenue model | **Flat SaaS** (default direction). Application fees are off the table without Connect; do not reopen destination charges / platform fee collection. |
| Discount model | Next data model to formalise; expresses a reduction, not a standalone amount |
| Jurisdiction rules | Late-fee / lien rules must be configurable per jurisdiction |
| Task reminders | Delivery channel undecided |
| GDPR | Note/comment redaction approach (activity log redaction decided above) |
| Automation `waiting` run status | Real delayed `logic.wait` (`ExecuteAutomationRun` with `->delay()`) needs a status other than `running` while parked, or run-log UI shows idle waits as running. Stub WaitHandler does not add the enum yet. |

## Active WIP

- Discounts API + UI (formal model still open)
- Contract detail / update surfaces
- Contact transactions
- Invoice / payment resources polish
- Recurring billing job (consume `billed_through`)
- **Panel `canEdit` stopgap:** overview inline edit ignores role/site-scope (always editable when `NativeFields.editable` / definition present). Gaps against the site-scoping rule in `07-people-and-auth.md` until panel auth UX ships. API still authenticates.
- **Site credential authorization stopgap:** `App\Support\Auth\SiteAccess::canManageSite()` lets every authenticated Employee manage every site's comms/Stripe credentials — there is no `Employee`↔`Site` assignment table yet to distinguish site-level staff from company-level roles. Controllers already call through this single helper so wiring real scoping later is a one-file change.
