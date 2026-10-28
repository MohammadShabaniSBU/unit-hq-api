# S06-02 — Payment links & public pay page

## Context

Contacts don't log in — the product's token-link idiom (offers) extends to money: the
operator (or, later, an automation) generates a link; the tenant opens a public page,
sees what they owe, pays by card, optionally saves it. This is the "Request payment"
quick action the inbox mockup always assumed.

## Scope

**In:** `payment_requests` table, create/cancel endpoints + "Request payment" UI, public
page (Elements), PaymentIntent creation server-side, save-card option (01's SetupIntent
combined via PI `setup_future_usage`), link lifecycle.
**Out:** ledger writes (03 — the page shows "processing" until the webhook lands), email/
SMS delivery of the link (comms phase — v1 is copy-to-clipboard), partial payments (the
link is pay-in-full for its targeted set).

## Schema changes

```sql
CREATE TABLE payment_requests (
    id BIGSERIAL PRIMARY KEY,
    token VARCHAR(64) NOT NULL,
    contract_id BIGINT NOT NULL REFERENCES contracts(id),
    payment_provider_account_id BIGINT NOT NULL REFERENCES payment_provider_accounts(id),
    charge_ids JSONB NOT NULL,            -- targeted set, validated open at create
    amount NUMERIC(10,2) NOT NULL,        -- snapshot of the set's open total at create
    currency CHAR(3) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
        -- pending | processing | paid | cancelled   (expiry is read-time)
    expires_at TIMESTAMPTZ NOT NULL,      -- default now + 7 days (config)
    stripe_payment_intent_id VARCHAR(64) NULL,
    save_card_requested BOOLEAN NOT NULL DEFAULT false,
    paid_payment_id BIGINT NULL REFERENCES payments(id),   -- 03 sets
    created_by BIGINT NULL REFERENCES employees(id),
    created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE UNIQUE INDEX pr_token_idx ON payment_requests (token);
```

## Behaviour

**Create** (`POST /api/contracts/{id}/payment-requests`
`{ charge_ids?, save_card?: bool }`): default charge set = all overdue-or-due open
charges; validate every id open + same currency; amount = open total via bcmath; account
via `ProviderAccountResolver` (422 `PaymentsNotConfigured` surfaces here, where an
operator can act). Returns the URL.

**Public endpoints** (no Sanctum, token-authenticated, offer-preview idiom):
- `GET /api/pay/{token}` — amount, currency, line summaries (charge type + period, no
  tenant PII beyond first name), publishable key, status. Expiry checked at read time
  (invariant 13 idiom); expired/cancelled/paid render terminal states, never 404 (the
  tenant needs words, not an error page).
- `POST /api/pay/{token}/intent` — creates (or re-uses stored) PaymentIntent on the
  account: minor units via `MinorUnits`, customer attached when the contact has one (01),
  `setup_future_usage=off_session` when the page's save-card box is ticked, metadata
  `{payment_request_id, contract_id}` — 03's join keys. Re-entrant: mid-payment refresh
  returns the same PI. Sets `status=processing` on client confirm... **no** — status
  moves `pending→processing` only when the PI reaches `processing`/`succeeded` via
  webhook; the page polls `GET /api/pay/{token}` for the flip. The client callback shows
  optimistic UI only.

**Cancel** (`POST …/payment-requests/{id}/cancel`): pending only; cancels the PI
best-effort.

**Public page** (panel, `/pay/{token}` route outside auth like the offer preview):
entity trading name header, amount, lines, Elements card form, save-card checkbox
(only when the request enabled it), pay button → confirmCardPayment → "Payment received —
confirming…" state polling until `paid`. Handles `requires_action` (3DS) inline. es-first
copy; the page inherits the site's country language (S03 interim rule).

## Panel surface

Contract detail Billing card + overdue banner gain **Request payment**: drawer with the
open-charge set pre-ticked, computed total, save-card toggle, expiry — creates and shows
the copyable link + QR (reuse the QR lib — operators hand phones across counters).
Requests list on the contract (status chips, copy, cancel). i18n `billing.paymentRequests.*`;
es: *Solicitar pago*, *Enlace de pago*.

## Invariants

- Invariant 6 — token, never PK; 13 — read-time expiry; 5 — `amount` is a request
  snapshot, open balance stays computed (a partially-paid-elsewhere set makes the link
  show a mismatch warning and refuse intent creation — recompute at intent time).
- Invariant 11 — nothing on this page writes the ledger; 03 does.

## Acceptance criteria

- [ ] Link renders the set, pays in test mode, page reaches `paid` only after the (03)
      webhook; kill-the-worker test shows optimistic UI but no ledger row until resume.
- [ ] Intent recomputation refuses when targeted charges were paid meanwhile.
- [ ] Save-card tick → `setup_future_usage` on the PI; card appears via 03 on the contact.
- [ ] Expired/cancelled/paid terminal states render; re-entrancy returns the same PI.
- [ ] Currency-mixed charge set refused at create.
- [ ] QR + copy work; requests list lifecycle correct.

## Tests required

| Test | Asserts |
|---|---|
| `PaymentRequestTest::create_validates_open_same_currency` | Set rules |
| `PaymentRequestTest::public_read_states_and_expiry` | Read-time, terminal copy |
| `PaymentRequestTest::intent_reentrant_and_recomputes` | Same PI; stale-set refusal |
| `PaymentRequestTest::no_ledger_write_from_page` | Invariant 11 |
| `PaymentRequestTest::minor_units_roundtrip` | bcmath, no float |
