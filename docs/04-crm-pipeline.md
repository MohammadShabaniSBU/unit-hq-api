# CRM / Leasing Pipeline

## The spine

```
Contact → Deal → Offer → OfferOption (selected) → Reservation → Contract
```

## Advanced filters (list search)

Ad-hoc nested AND/OR filters over native fields **and** custom attributes, for Contacts / Deals / Offers / Reservations / Units / Contracts:

- `GET /api/{entity}/filters/schema` — generative field list (native + active `attr:{id}`).
- `POST /api/{entity}/search` — body `{ filter, sort?, page, per_page, search?, status? }`; response is standard paginated shape.
- Filter tree is **not persisted** server-side (panel mirrors applied tree in `?f=`). Saved views deferred.
- Engine: `App\Support\Filtering\` (`FilterBuilder`, `FilterTreeValidator`, `FilterableFields`, …).

## Contact

Durable **person record holding identity only**. Contacts do **not** log in — they interact via offer token links.

- Multiple emails / phones live in `ContactChannel` (`type`, `value`, `label`, `is_primary`, `opted_in`) — the contact row itself stays clean.
- **Partial unique index** enforces only one primary channel per type per contact.
- Sites are associated via the `contact_sites` pivot (many-to-many). Create requires a `site_id`; optional `phone` creates a primary phone channel in the same transaction. Detail views still show activity **across all sites** — a Contact is not site-scoped as a subject (`SubjectSite` → null).

### AI summary (Contact)

Operator-triggered card on the Contact detail overview. One current summary per contact (`ai_summaries`, morph `summarizable`); regeneration inserts a new row and supersedes the previous current only on success. No scheduled/automatic regeneration.

- `GET /api/contacts/{contact}/ai-summary` — current + in-flight + staleness + `can_generate`
- `POST /api/contacts/{contact}/ai-summary` — queue a generation (202)
- `GET /api/contacts/{contact}/ai-summary/history` — paginated superseded rows

## Deal

The pursuit record: pipeline stage, forecast, intent. Also carries expected-need fields: `expected_move_in`, `expected_stay_length`, `expected_stay_period`, `desired_size`, `desired_unit_class_id`. Optional link target for interactions.

### AI summary (Deal)

Same card and job contract as Contact, scoped to the Deal. Operator-triggered only.

- `GET /api/deals/{deal}/ai-summary`
- `POST /api/deals/{deal}/ai-summary`
- `GET /api/deals/{deal}/ai-summary/history`

## Offer — the commercial proposal

Belongs to a Deal and a Contact. Sits between Deal and Reservation.

- **Shareable link uses `offers.token`** — a cryptographically random URL-safe token, **never the primary key**. Public route: `/api/offers/token/{token}`.
- `expires_at` timestamp; **expiry is checked at read time**, not via a background job.
- Status flow: `draft → sent → viewed → accepted → expired`.
- The Offer does **not** hold a back-reference to the Reservation.
- **Provenance:** `source` (`operator` \| `public_link` \| `ai_agent` \| `automation`) and nullable `ai_agent_id` (required when `source = ai_agent`). A customer-facing agent may create a draft Offer through `App\Support\Leasing\OfferCreation` under invariant 54b; it does not send it.
- HTTP and customer-facing agent creation route through `App\Support\Leasing\` (`OfferCreation`). Token, expiry, status, and contact are server-derived.

## OfferOption — line item

Each option is presented to the contact at the **UnitClass / rate level** — no unit number is shown commercially.

- Carries: a `unit_class_rate_id`, an optional Discount, operator-written label + description, display order.
- Internally, a specific candidate `unit_id` is **pre-resolved and pinned on the `offer_options` row itself at create/update time** (`Unit::resolveUnitIdForRate(...)` in `OfferOptionController::store`/`update`), well before offer acceptance — the unit isn't first chosen at Reservation.
- When the contact picks an option, `selected_at` is written on that row.
- **Partial unique index** on `offer_id WHERE selected_at IS NOT NULL` → exactly one selection per offer.

### Offer acceptance — one DB transaction

`App\Support\Leasing\OfferAcceptance`. One transaction:

1. Set `offer_options.selected_at`
2. Flip `offers.status = accepted`
3. Insert a `Reservation` referencing that OfferOption — this is where the pre-resolved unit becomes **operative** (occupancy/hold); if it's no longer available, a fresh unit is re-resolved from the rate at this point.

The public offer-accept path stamps the reservation `source = public_link`, not the parent offer's source. An agent-created offer that a prospect accepts still produces a public-link reservation.

## OfferDelivery

Separate **send-event log** — one row per send: channel (email / SMS / WhatsApp), recipient address, send timestamp, delivery timestamp, delivery status. Kept separate so an offer can be resent across channels **without touching the Offer record**. Every send also writes an `Interaction` row (see `06-communications.md`).

## Reservation

The **inventory hold** — always references a **specific unit**, never a unit type. Holds `offer_option_id` FK.

- **Provenance:** same `source` / `ai_agent_id` columns as Offer. A customer-facing agent may create a Reservation through `App\Support\Leasing\ReservationCreation` under invariant 54b (seeded `propose`). Unit selection and expiry are server-derived; `unit_id` and `expires_at` are never model arguments.
- HTTP and customer-facing agent creation route through `ReservationCreation`. Agent-sourced active holds are uniqueness-checked per (contact, site, unit class) inside that entry point.

## Contract (ERD may say "Lease" — code says Contract)

The operational and billing anchor. Creation accepts `signature_mode: immediate | remote` (default `immediate`) on both walk-in create and reservation convert.

### Status lifecycle (signing)

- **`awaiting_signature`** — remote path only. Unit protected by a `unit_holds` row (`hold_type = contract_signature`); **no charges, no invoice, no occupancy**. Cancelling releases the hold with zero ledger rows.
- **`pending` / `active`** — reached when the contract becomes signed: at creation for walk-in (`immediate`), or when the e-sign webhook completes for remote. `ContractSigning::complete()` is the sole implementation (invariant 20).
- Later stages unchanged: `notice_given` → `ended`, plus `cancelled`.

### Signing & documents

- Remote flow: create awaiting → generate contract document (template family `channel = document`) → send envelope via the active e-sign provider → webhook lands signed PDF + certificate (immutable hashes) → `ContractSigning::complete()`.
- Board column **Awaiting signature** shows live-envelope aging (`sent_at` / `viewed_at` / `expires_at`; amber when expiring ≤ 3 days). Attention chips on the contracts index: declined, signed-after-cancellation (`post_cancellation`).

### Lifecycle operations

- **Vacate**: `POST contracts/{contract}/vacate-preview` and `POST contracts/{contract}/vacate` (`ContractController`, logic in the `VacatesContracts` concern) — ends the contract, releases the unit, and settles the deposit; the preview endpoint returns the settlement breakdown without committing.
- **Transfers**: `ContractTransfer` model tracks moving a contract to a different unit — `POST contracts/{contract}/transfer-preview` and `POST contracts/{contract}/transfer`. It's an audit record only, deliberately redundant with the occupancy and item rows it produces.
- **Rate changes**: `ContractRateChangeController` (`POST contracts/{contract}/rate-changes`) — schedules an effective-dated rate change on an active contract.
- **Contract notices**: `ContractNotice` model is an append-only audit trail (rate changes, delinquency, move-out notices, etc.), never updated or deleted — `POST contract-notices/{contractNotice}/mark-sent` records the send.
- **Autopay**: `GET`/`PUT contracts/{contract}/autopay` and `POST contracts/{contract}/autopay/retry` — per-contract autopay enrollment and manual retry of a failed charge.
- **Contract documents**: `ContractDocument` model — generated contract paperwork (`GET`/`POST contracts/{contract}/documents`, plus `/preview`, `/regenerate`, `/pdf`), distinct from the e-sign envelope flow above.

### Billing snapshots

- **Line items live on `ContractItem`** (polymorphic: unit, insurance, …) — versioned rows, not flat FKs on the contract. Every version carries a required `price_id` (amount/currency read through the referenced, immutable Price); there is no `amount` column on the item itself. A separate `base_rate` decimal exists only for discount-tracking math.
- Items also carry optional `description`, `declared_goods_value`, and tax snapshots (`tax_rate_id`, `tax_rate_snapshot`).
- At signing the contract snapshots org billing: interval / count, `billing_anchor_model`, derived `billing_anchor_date`, `proration_method`, `move_in_date`, `deposit_amount`, and sets `billed_through` (billing cursor).
- **First-period charges are written in the same transaction** as the contract becoming signed (one charge per item + optional deposit). Preview (`convert-preview`) uses the same `BillingMath` / `ContractBilling` path so UI and ledger never diverge. Full detail: `05-billing-ledger.md`.
- `reservation_id` is **nullable** to support walk-ins.
- The ledger attaches to the Contract: every charge, payment, and allocation references it.
- Core activity: `contract.signed` via `RecordsActivity::core` (money in properties as strings).
