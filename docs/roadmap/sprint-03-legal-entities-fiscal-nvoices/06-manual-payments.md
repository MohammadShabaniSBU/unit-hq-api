# S03-06 — Manual payment recording

## Context

A walk-in signs, receives their invoice, and pays €150 in cash. Nothing can record that.
The rewritten invariant 11 already permits the third rail — *"Manual (cash, transfer):
written by an authenticated employee with a recorded causer"* — but no endpoint or UI
exists. Every client has manual steps in their process; this is the smallest thing that
makes the digital ledger match the till.

## Scope

**In:** manual payment endpoint (cash / bank transfer / card-terminal-external), allocation
to charges, reversal for mistakes, panel drawer on contract detail.
**Out:** any processor integration (S06), refund payouts (S07), cash-drawer / daily-close
reporting (S17 — but the `method` recorded here is what that report will read).

## Behaviour

`POST /api/contracts/{id}/payments`:

```json
{ "amount": "150.00", "method": "cash",        // cash | bank_transfer | card_external
  "received_on": "2026-08-02",
  "reference": "optional — transfer ref / receipt no.",
  "allocations": [ { "charge_id": 123, "amount": "150.00" } ]   // optional
}
```

One transaction: insert the `payments` row (amount+currency snapshot, currency from the
contract; causer = authenticated employee), insert `allocations`. If `allocations` is
omitted, auto-allocate oldest-due-first across open charges — the behaviour a front-desk
operator expects — leaving any remainder unallocated (computed credit, invariant 5).
Over-allocation beyond the payment or beyond a charge's open amount → 422.

**Mistakes are reversed, never edited** (invariant 3): `POST /api/payments/{id}/reverse`
`{ reason }` writes a negative payment via `reversal_of_payment_id`, plus opposing
allocation rows. Wrong-amount cash entries = reverse + re-enter.

Activity: `payment.recorded` / `payment.reversed` via `RecordsActivity::core`, money as
strings, `method` and `reference` in properties — this is the audit trail a cash dispute
falls back on.

Invoices are untouched: the invoice states what was billed; payment state stays computed.
The invoice detail may *display* paid/outstanding, computed live, never stored.

## Panel surface

Contract detail → Billing summary card gains **Record payment**: drawer with amount
(prefilled = overdue balance), method, date, reference, and an allocation table
(auto-allocation preview, editable). Payments tab rows gain method badge + reverse action
(confirm + reason). Invoice detail shows computed paid status chip. i18n
`billing.payments.manual.*`; es: *Registrar pago*, *Efectivo*, *Transferencia*.

## Invariants

- Invariant 3 — append-only; reversal rows only.
- Invariant 5 — no stored paid/outstanding anywhere, including on invoices.
- Invariant 11 (rewritten) — manual rail: authenticated employee, recorded causer.
- Invariant 10 — `NUMERIC(10,2)` + currency snapshot; bcmath in allocation math.

## Acceptance criteria

- [ ] Cash payment records with causer, allocates oldest-first by default, and the
      contract's computed overdue drops accordingly.
- [ ] Explicit allocations validated: per-charge open amount and payment total capped.
- [ ] Unallocated remainder shows as computed credit; nothing stored.
- [ ] Reversal writes negative payment + opposing allocations; original untouched.
- [ ] Invoice detail shows computed paid state; no schema change on invoices.
- [ ] Activity rows carry method, reference, money-as-strings.
- [ ] Seeder records at least one cash payment and one reversed mistake.

## Tests required

| Test | Asserts |
|---|---|
| `ManualPaymentTest::records_with_causer_and_method` | Rail-3 semantics |
| `ManualPaymentTest::auto_allocates_oldest_due_first` | Default behaviour |
| `ManualPaymentTest::over_allocation_rejected` | Both caps |
| `ManualPaymentTest::remainder_is_computed_credit` | Invariant 5 |
| `ManualPaymentTest::reversal_appends_never_edits` | Invariant 3 |
| `ManualPaymentTest::overdue_recomputes_after_payment` | Ledger arithmetic |
