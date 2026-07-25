# Open Decisions & Active Work

## Decided (do not reopen)

- **Tenancy: mono-tenant.** Single database, no company scoping. (Supersedes earlier multi-tenant notes.)
- Hierarchy has **no Building layer** — Site → Unit.
- Pipeline entity naming: **Contract**, not Lease.
- Interaction model name (over CommunicationLog / Communication).
- Insurance is **site-scoped** via InsuranceRate.
- Stripe Connect with the company's connected account as merchant of record.
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

## Undecided

| Topic | Options / notes |
|---|---|
| Revenue model | Application fees on rent vs. flat SaaS subscription vs. both |
| Stripe charge type | Direct vs. destination charges — **tied to the revenue model** |
| Discount model | Next data model to formalise; expresses a reduction, not a standalone amount |
| Jurisdiction rules | Late-fee / lien rules must be configurable per jurisdiction |
| Task reminders | Delivery channel undecided |
| GDPR | Note/comment redaction approach (activity log redaction decided above) |

## Active WIP

- Discounts API + UI (formal model still open)
- Contract detail / update surfaces
- Contact transactions
- Invoice / payment resources polish
- Recurring billing job (consume `billed_through`)
- **Panel `canEdit` stopgap:** overview inline edit ignores role/site-scope (always editable when `NativeFields.editable` / definition present). Gaps against the site-scoping rule in `07-people-and-auth.md` until panel auth UX ships. API still authenticates.
