# Billing, Ledger & Stripe

## Three layers

| Layer | Tables | Role |
|---|---|---|
| Charges / Payments | `charges`, `payments` | Atomic debit / credit — **append-only** |
| Billing periods | `billing_periods` | Group charges by period — **display grouping, not source of truth** (name freed for S03 fiscal **Invoice**) |
| Allocations | `allocations` | Map payment money onto specific charges |

The design evolved from a single generic ledger-entries table to explicit `Charge` and `Payment` models, but the ledger principles are unchanged.

### Invoice vs Statement (D5)

- **Invoice** — a fiscal document. Numbered, immutable once issued, belongs to a series. From
  S03 onward this is the source of truth for what was billed.
- **Statement** — a computed view of what a contact owes right now across contracts. Never a
  stored row. Never numbered.
- The display-grouping table is **`billing_periods`** (renamed in S01-00b) so the name
  `invoices` is free for the fiscal document.

## Hard invariants

- **Append-only and immutable.** Corrections are opposing rows via `reversal_of_*_id` — never edits, never deletes.
- **Never store computed money state.** Balance owed, overdue status, and unallocated credit are always **computed**, never cached as columns.
  - Balance owed = total charges − total payments.
  - **Overdue is calculated per-charge by due date**, not from a net balance sign.
- Charges carry a **type** and a **due date** so late-fee assessment and lien eligibility can be automated. Jurisdiction-specific rules are configurable.
- The ledger attaches to the **Contract** — every charge, payment, and allocation references one.
- **`deposit` charges are not revenue** — exclude them from revenue rollups / analytics.
- **Revenue is grouped by currency, never summed across it** (invariant 30). Use
  `App\Support\Billing\RevenueByCurrency::group()`.
- **One contract, one currency** (invariant 35). `contracts.currency` is snapshotted at
  signing; every charge and payment carries the same currency. Assert via
  `App\Support\Billing\CurrencyGuard`.

### Charge types (D3)

`ChargeType` enum (cast on `Charge`): `rent`, `insurance`, `deposit`, `late_fee`, `lien_fee`,
`other`, `adjustment`, `write_off`, `refund`. `isRevenue()` is false for `deposit`,
`write_off`, and `refund`.

| Type | Sign | Revenue | Notes |
|---|---|---|---|
| `adjustment` | ± | ± | Manual correction. Reason string required. Counts as revenue when positive and the underlying charge did. |
| `write_off` | negative | **excluded** | Operator forgives a debt. Carries a net/tax split mirroring the charge it forgives. VAT recovery treatment deferred to S03. |
| `refund` | positive | **excluded** | Money returned to the tenant. A **charge**, not a negative payment. |

`refund` as a charge is correct given `balance = Σ charges − Σ payments` — it debits the
tenant's credit. A refund does **not** reverse revenue; revenue reversal is a **credit note
(S03)**. Excluding `refund` from `isRevenue()` is correct today (counting it would increase
revenue when money went out) but leaves the original rent in revenue after a refund — that is
expected until credit notes exist; do not “fix” `isRevenue()` in S08.

`write_off` cannot use `reversal_of_charge_id` for partial write-offs — it is a new charge with
its own amount, optionally referencing the related charge for reporting.

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
| `billing_horizon_days` | Non-negative integer, default `0`. Bill periods whose start is within site-today + N days. **Operational only** — not snapshotted at signing; changing it never rewrites contract windows or amounts (invariant-18 exemption). |

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
- Recurring windows (S05): `nextPeriod` / `periodsBetween` produce end-exclusive full-period
  windows from the `billed_through` cursor. Anniversary ends are anchor-derived
  (`addMonthsNoOverflow` from the original anchor, never from the cursor) so month-end
  contracts never drift. Calendar* + `interval_count > 1` throws `UnsupportedCadence`.

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

`charge_type` is the `ChargeType` enum (see D3 table above). First-period writers use
`rent` / `insurance` / `deposit`.

## Payments (credentials & confirmation)

Payment credentials, webhook routing, and merchant-of-record status belong to the
**legal entity**, not to sites. Per-account webhook route tokens follow the `offers.token`
pattern (crypto-random, never the PK). Full model — Stripe, SEPA bank-file, Verifactu,
invoice series — is in `roadmap/architecture-payments-and-fiscal.md` (authoritative).

Credentials live on `payment_provider_accounts` scoped to `legal_entity_id`
(Settings → Payments / entity detail). Inbound webhooks route by opaque
`account_token` (`POST /api/webhooks/stripe/{account_token}`); idempotency on
`stripe_webhook_events` is per account. Credential handling rules
(create/rotate/remove logging, masking, blank-unchanged) stay shared with
`communication_accounts` — see `06-communications.md` and `App\Support\Credentials`.
Entity Stripe settings and other credential routes authorize with
`Permission::CredentialManage` (policies / ability gates — not a SiteAccess helper).

**Invariant 11 — Payment confirmation is rail-specific, and never optimistic from the client.**

- **Stripe:** payments are written only on receipt of a verified webhook, with per-account
  idempotency keys. Never from a client-side success callback.
- **Payment links:** operators create `payment_requests` (tokenised public `/pay/{token}`
  page, offer-token idiom). The page creates/reuses a PaymentIntent and confirms
  client-side; it **never** writes `payments` / allocations. Status flips to `paid` and
  ledger rows land only from the verified `payment_intent.succeeded` webhook (S06-03).
  Expiry is read-time (invariant 13); `amount` is a snapshot of the targeted open set at
  create — intent creation refuses when the open total no longer matches.
- **Saved cards:** each contact has at most one Stripe Customer per
  `payment_provider_account` (`stripe_customers`). Attached PaymentMethods are mirrored
  locally in `payment_methods` (display label + Stripe ids only — never PAN/CVC). Local
  instrument rows are created exclusively from `setup_intent.succeeded` webhooks; client
  callbacks write nothing.
- **Bank SEPA DD:** generating a direct-debit collection file is **not** a payment. A
  payment is written on the run's settlement date. A return writes a reversal payment via
  `reversal_of_payment_id` — never an edit or delete.
- **Manual (cash, transfer):** written by an authenticated employee with a recorded causer
  via `POST /api/contracts/{id}/payments` (`method`: `cash` | `bank_transfer` |
  `card_external`, plus `received_on` / optional `reference`). Mistakes are reversed with
  `POST /api/payments/{id}/reverse` (opposing payment + allocations). Activity:
  `payment.recorded` / `payment.reversed`.

In all cases the ledger is the system of record; provider events are reconciled inputs.

## Recurring billing runs (S05-01 / S05-02 / S05-03)

`php artisan billing:run` drives `App\Support\Billing\BillingRunEngine`: eligibility for
`active` / `notice_given` contracts with `billed_through <= horizon`, then one locked
transaction per contract. Horizon days come from `BillingSettings.billing_horizon_days`
(resolved per contract via `SiteClock`). Observability lives in append-only `billing_runs`
/ `billing_run_items`. Period arithmetic is `BillingMath::periodsBetween`; per-period
charge + invoice generation is `RecurringBilling::generatePeriod`.

**Activation:** `php artisan contracts:activate` flips `pending → active` when site-today
`>= move_in_date` (`ContractTransition::assert` + core activity `contract.activated`).
Per-contract failure isolation; cancelled-pending never activates.

**Scheduler** (`bootstrap/app.php`): both commands run hourly; activation is registered
**before** billing so same-tick runs activate move-ins before eligibility is evaluated.
Manual trigger: authenticated `POST /api/billing-runs` `{ dry_run?: bool }` with
`trigger=manual` and `created_by` = the employee; requires
`Permission::BillingRunExecute`.

**Panel read APIs (S05-04):** authenticated `GET /api/billing-runs` (paginated list with
per-currency billed totals), `GET /api/billing-runs/{id}` (detail + items; optional
`?outcome=` filter), and `GET /api/contracts/{id}/next-bill` →
`{ window: { start, end }, amount, currency } | null` computed via
`BillingMath::nextPeriod` + `RecurringBilling::estimatePeriodGross` (never stored).
Contract show `billing_summary.last_failed_billing_run` surfaces the latest item when
that item's outcome is `failed` (clears after a later billed/skipped item).

**Stop line (notice_given):**
`stop = max(scheduled_move_out_on, notice_given_on + notice_period_days)` — the same
later-of expression as vacate's final billing date. It is a **condition, never a cursor
write**. The shell pre-checks `billed_through >= stop` → `skipped/stop_line` without
calling `nextPeriod`. Catch-up stops when the next window's start `>= stop`; periods
already billed in that transaction stand and the cursor advances only to the last billed
period end. Periods straddling the stop line bill in full (vacate settlement credits the
tail).

**Per period:** `itemsOn(window.start)` → full price amount + item tax snapshot → charges
(`rent` / `insurance`, `due_date = window.start`) → `InvoiceIssuer::issue`. Currency
mismatch → `failed/currency_mismatch`; fiscal refusal → `failed/fiscal_blocker` (period
rolls back, retries next run). Idempotency is the cursor lock only (invariant 37 — one
writer: forward to a billed period end under the row lock).

## Out of scope (current billing slice)

- Deposit refund / deduction lifecycle.
- Per-contract cadence override.
- Multi-week / multi-month epoch for `interval_count > 1` on calendar models (boundaries are still every month-day / every weekday).
