# CRM / Leasing Pipeline

## The spine

```
Contact → Deal → Offer → OfferOption (selected) → Reservation → Contract
```

## Contact

Durable **person record holding identity only**. Contacts do **not** log in — they interact via offer token links.

- Multiple emails / phones live in `ContactChannel` (`type`, `value`, `label`, `is_primary`) — the contact row itself stays clean.
- **Partial unique index** enforces only one primary channel per type per contact.
- Contact detail views show activity **across all sites** — a Contact is not site-scoped.

## Deal

The pursuit record: pipeline stage, forecast, intent. Optional link target for interactions.

## Offer — the commercial proposal

Belongs to a Deal and a Contact. Sits between Deal and Reservation.

- **Shareable link uses `offers.token`** — a cryptographically random URL-safe token, **never the primary key**. Public route: `/api/offers/token/{token}`.
- `expires_at` timestamp; **expiry is checked at read time**, not via a background job.
- Status flow: `draft → sent → viewed → accepted → expired`.
- The Offer does **not** hold a back-reference to the Reservation.

## OfferOption — line item

Each option references a **UnitClass, never a specific unit**.

- Carries: a price, an optional Discount, operator-written label + description, display order.
- When the contact picks an option, `selected_at` is written on that row.
- **Partial unique index** on `offer_id WHERE selected_at IS NOT NULL` → exactly one selection per offer.

### Offer acceptance — one DB transaction

1. Set `offer_options.selected_at`
2. Flip `offers.status = accepted`
3. Insert a `Reservation` referencing that OfferOption (with a **specific `unit_id`**)

## OfferDelivery

Separate **send-event log** — one row per send: channel (email / SMS / WhatsApp), recipient address, send timestamp, delivery timestamp, delivery status. Kept separate so an offer can be resent across channels **without touching the Offer record**. Every send also writes an `Interaction` row (see `06-communications.md`).

## Reservation

The **inventory hold** — always references a **specific unit**, never a unit type. Holds `offer_option_id` FK.

## Contract (ERD may say "Lease" — code says Contract)

The operational and billing anchor, created at signing (walk-in or reservation convert).

- **Line items live on `ContractItem`** (polymorphic: unit, insurance, …), each with its **own `amount`** (net period price) — not flat FKs on the contract. Column was renamed from `rate` → `amount`.
- Items also carry optional `price_id`, `description`, `declared_goods_value`, and tax snapshots (`tax_rate_id`, `tax_rate_snapshot`).
- At signing the contract snapshots org billing: interval / count, `billing_anchor_model`, derived `billing_anchor_date`, `proration_method`, `move_in_date`, `deposit_amount`, and sets `billed_through` (billing cursor).
- **First-period charges are written in the same transaction** as the contract (one charge per item + optional deposit). Preview (`convert-preview`) uses the same `BillingMath` / `ContractBilling` path so UI and ledger never diverge. Full detail: `05-billing-ledger.md`.
- `reservation_id` is **nullable** to support walk-ins.
- The ledger attaches to the Contract: every charge, payment, and allocation references it.
- Core activity: `contract.signed` via `RecordsActivity::core` (money in properties as strings).
