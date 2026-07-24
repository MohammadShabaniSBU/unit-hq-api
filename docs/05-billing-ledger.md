# Billing, Ledger & Stripe

## Three layers

| Layer | Tables | Role |
|---|---|---|
| Charges / Payments | `charges`, `payments` | Atomic debit / credit — **append-only** |
| Invoices | `invoices` | Group charges by period — **display grouping, not source of truth** |
| Allocations | `allocations` | Map payment money onto specific charges |

The design evolved from a single generic ledger-entries table to explicit `Charge` and `Payment` models, but the ledger principles are unchanged.

## Hard invariants

- **Append-only and immutable.** Corrections are opposing rows via `reversal_of_*_id` — never edits, never deletes.
- **Never store computed money state.** Balance owed, overdue status, and unallocated credit are always **computed**, never cached as columns.
  - Balance owed = total charges − total payments.
  - **Overdue is calculated per-charge by due date**, not from a net balance sign.
- Charges carry a **type** and a **due date** so late-fee assessment and lien eligibility can be automated. Jurisdiction-specific rules are configurable.
- The ledger attaches to the **Contract** — every charge, payment, and allocation references one.
- **`deposit` charges are not revenue** — exclude them from revenue rollups / analytics.

## Contract billing (cadence, anchor, first period)

Billing behaviour is configured at org level (`BillingSettings`) and **snapshotted onto the contract at signing**. Later settings changes never rewrite existing contracts.

### Org settings (`BillingSettings`)

| Setting | Values / notes |
|---|---|
| `default_currency` | ISO code |
| `default_billing_interval` | `day` \| `week` \| `month` |
| `default_billing_interval_count` | positive integer (e.g. 1 = every month) |
| `billing_anchor_model` | `anniversary` \| `calendar` \| `calendar_week` |
| `billing_anchor_day` | Day-of-month `1..28` when `calendar`; ISO weekday `1=Mon..7=Sun` when `calendar_week` |
| `proration_method` | `daily` (default) \| `full_period` \| `none` |
| `default_deposit_amount` | `NUMERIC(10,2)`, default `0.00` |

**Cadence locks:**

- `calendar` requires `default_billing_interval = month`
- `calendar_week` requires `default_billing_interval = week`

v1 has **no per-contract cadence override** — every contract inherits org interval + count at signing.

### Snapshotted on `contracts`

| Column | Meaning |
|---|---|
| `billing_interval` / `billing_interval_count` | Cadence |
| `billing_anchor_model` | How `billing_anchor_date` was derived |
| `billing_anchor_date` | **Resolved fact** at signing (not reassigned later) |
| `proration_method` | How stubs are charged |
| `move_in_date` | Occupancy start; first charge `due_date` |
| `deposit_amount` | Snapshot of deposit charged at create |
| `billed_through` | **Billing cursor** — stored date the recurring job advances; **not** cached money |

### Anchor models

| Model | Anchor date | Stub? |
|---|---|---|
| `anniversary` | = `move_in_date` | Never — every first period is full |
| `calendar` | Next day-of-month boundary at-or-after move-in | Yes when move-in is off-boundary |
| `calendar_week` | Next ISO weekday at-or-after move-in | Yes when move-in is off-boundary |

Boundary convention: move-in day is **billed**; anchor is the **first day of the next period** (exclusive end of stub).  
`days_occupied = anchor − move_in` (midnight-normalised calendar days).

**Worked stubs:**

- Monthly on the 1st, move-in 10th of a 30-day month → stub 10th→1st = **21 / 30** days.
- Weekly on Monday, move-in Wednesday → stub Wed→Mon = **5 / 7** days.

### Proration arithmetic (`App\Support\Billing\BillingMath`)

Support-tier helper (same tier as `RecordsActivity` — **not** `app/Services/`). Used by contract store, reservation convert, and convert-preview so the panel preview never diverges from real charges.

- Money as decimal strings (`NUMERIC(10,2)`).
- **bcmath** at intermediate `SCALE=8`; bcmath **truncates**, so rounding is explicit and happens **once** via `round2` (half-up).
- **Multiply before divide** — dividing first silently loses cents  
  (`300/28×19 = 203.49` wrong; `300×19/28 → 203.57` correct).
- `proration_method` only bites when a stub exists:
  - `daily` — prorate by days occupied / days in containing period
  - `full_period` — charge full period amount for the stub window
  - `none` — no first-period charges; `billed_through = anchor` (defer to recurring job)

### Exclusive tax

- Item / charge **net** is the period amount (after proration).
- `tax = round2(net × rate / 100)`; `gross = net + tax`.
- Rate % is snapshotted onto `contract_items.tax_rate_snapshot` and each related charge.
- See `03-pricing.md` for the `tax_rates` catalogue.

### First-period create spine (store / convert / convert-preview)

One DB transaction. Contract-level window/anchor computed **once**; one charge **per item** in that shared window; deposit appended **separately** (never inside the per-item loop).

1. Snapshot cadence / anchor model / proration / deposit onto the contract.
2. `BillingMath::resolveAnchorDate` → store `billing_anchor_date` (never assign `move_in` as the calendar/week anchor).
3. `BillingMath::firstChargeWindow` → `null` means full first period; else stub window.
4. For each `ContractItem` (unit → `rent`, insurance → `insurance`):
   - Net via proration rules above.
   - `BillingMath::applyTax(net, item.tax_rate_snapshot)`.
   - Insert charge with `period_start` / `period_end`, `net_amount`, `tax_amount`, `amount` (gross), `due_date = move_in`, `contract_item_id`.
5. If `deposit_amount > 0`: insert one `charge_type = deposit` charge (net = deposit, tax = 0, no period window).
6. Set `billed_through` once (period end if full; anchor if stub).
7. `RecordsActivity::core('contract.signed', …)` with money props as strings.

Orchestration: `App\Support\Billing\ContractBilling` + controller concern `GeneratesFirstPeriodCharges`.

### Charge fields (billing-relevant)

Beyond type / due date / gross `amount`:

| Field | Role |
|---|---|
| `period_start` / `period_end` | Window covered (null for deposit) |
| `net_amount` | Pre-tax |
| `tax_rate_snapshot` / `tax_amount` | Exclusive tax snapshot |
| `contract_item_id` | Trace first-period charges to their line |

`charge_type` includes at least: `rent`, `insurance`, `deposit`, plus fee types (`late_fee`, `lien_fee`, `other`).

## Stripe Connect

- One **connected account** for the storage company; the **connected account is the merchant of record**.
- The platform **never collects funds into its own account** — doing so would make it a money transmitter requiring PSD2 authorization (Ireland).
- **The ledger is the system of record.** Stripe events are *inputs* reconciled against it.
- Payments are confirmed **from webhooks with idempotency keys** (`stripe_webhook_events` table) — **never optimistically from the client**.
- Charge type (direct vs. destination charges) is not finalised — it depends on the revenue model (see `10-open-decisions.md`).

## Out of scope (current billing slice)

- Recurring billing job beyond first-charge generation (cursor exists; job does not yet).
- Deposit refund / deduction lifecycle.
- Per-contract cadence override.
- Multi-week / multi-month epoch for `interval_count > 1` on calendar models (boundaries are still every month-day / every weekday).
