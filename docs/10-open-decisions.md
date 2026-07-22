# Open Decisions & Active Work

## Decided (do not reopen)

- **Tenancy: mono-tenant.** Single database, no company scoping. (Supersedes earlier multi-tenant notes.)
- Hierarchy has **no Building layer** — Site → Unit.
- Pipeline entity naming: **Contract**, not Lease.
- Interaction model name (over CommunicationLog / Communication).
- Insurance is **site-scoped** via InsuranceRate.
- Stripe Connect with the company's connected account as merchant of record.
- **GDPR activity/system_events redaction:** `contacts:redact {contact}` nulls an allowlisted set of JSON keys in `activity_log.properties` and surviving `system_events.payload` for rows whose subject is that contact, then inserts a tier-3 `contact.redacted` event (fact only — never what was removed). Config: `config/redaction.php`.
- **Contract billing model (contract/contract_item/tax_rate enhancement):**
  - **Tax basis: exclusive.** Item `amount` is net; `tax = round(net × rate/100, 2)`; `gross = net + tax`.
  - **Billing timing: in advance.** First charge `due_date` = move-in.
  - **Default proration: `daily`** (org setting; snapshotted per contract at signing).
  - **Billing anchor: selectable per org**, snapshotted per contract — `anniversary` (anchor = move-in, every period full, no stub) or `calendar` (anchor = fixed day-of-month; off-boundary move-in prorates a stub).
  - **Proration basis: calendar-days** — divide by the real day-length of the containing period, never a nominal 30. Arithmetic is `bcmath`, multiply-before-divide at high intermediate scale, single half-up round at the end (dividing first silently truncates money).
  - **Calendar cadence is month-only in v1** — `week`/`day` + `calendar` anchor is a validation error.
  - **Cadence has no per-contract override in v1** — every contract inherits the org's billing interval/count at signing.
  - **`billed_through` is a stored cursor**, not derived from invoices and never cached money.

## Undecided

| Topic | Options / notes |
|---|---|
| Revenue model | Application fees on rent vs. flat SaaS subscription vs. both |
| Stripe charge type | Direct vs. destination charges — **tied to the revenue model** |
| Discount model | Next data model to formalise; expresses a reduction, not a standalone amount |
| Jurisdiction rules | Late-fee / lien rules must be configurable per jurisdiction |
| Task reminders | Delivery channel undecided |
| GDPR | Note/comment redaction approach (activity log redaction decided above) |

## Active WIP (from git status)

- Discounts API + UI
- Contract detail / update
- Contact transactions
- Invoice / payment resources
- Billing seeder
- Reservation → contract conversion paths

Billing / discount / contract UX is actively landing on top of the schema described in these docs.
