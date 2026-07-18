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

## Stripe Connect

- One **connected account** for the storage company; the **connected account is the merchant of record**.
- The platform **never collects funds into its own account** — doing so would make it a money transmitter requiring PSD2 authorization (Ireland).
- **The ledger is the system of record.** Stripe events are *inputs* reconciled against it.
- Payments are confirmed **from webhooks with idempotency keys** (`stripe_webhook_events` table) — **never optimistically from the client**.
- Charge type (direct vs. destination charges) is not finalised — it depends on the revenue model (see `10-open-decisions.md`).
