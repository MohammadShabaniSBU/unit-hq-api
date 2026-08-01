# S06-01 — Customers & saved payment methods

## Context

Autopay and one-click repeat payments need a card **on file**: a Stripe Customer per
contact per provider account, and PaymentMethods attached to it, mirrored locally as the
`payment_methods` table the architecture doc specified. Local rows are display + reference
only — card data never touches the application.

## Scope

**In:** `stripe_customers` map, `payment_methods` per §2 (Stripe subset), SetupIntent
issuance for the save-card flows, detach/archive, contact panel card.
**Out:** the public page that *collects* the card (02 hosts the SetupIntent UI),
`bank_sdd` rows (parked with SEPA), default-method *usage* (04).

## Schema changes

```sql
CREATE TABLE stripe_customers (
    id BIGSERIAL PRIMARY KEY,
    contact_id BIGINT NOT NULL REFERENCES contacts(id),
    payment_provider_account_id BIGINT NOT NULL REFERENCES payment_provider_accounts(id),
    stripe_customer_id VARCHAR(64) NOT NULL,
    created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE UNIQUE INDEX sc_pair_idx ON stripe_customers (contact_id, payment_provider_account_id);
```

`payment_methods` exactly per `architecture-payments-and-fiscal.md` §2 — including
`sepa_mandate_id` (nullable, unused until SEPA unparks) so the table needs no later
migration — with `type` limited in validation to `stripe_card | manual` this sprint
(`card_external` recording from S03-06 does not create rows here; it is an event, not an
instrument).

## Implementation notes

- `App\Support\Payments\StripeCustomers::for(Contact, PaymentProviderAccount)` —
  find-or-create (name + email from primary channel), race-safe on the unique index
  (catch, re-read).
- Save-card flow is SetupIntent-based: `POST /api/contacts/{id}/payment-methods/setup`
  `{ contract_id }` (contract picks the entity via the 00 resolver) → creates SetupIntent
  on the customer, returns `client_secret` + publishable key. Confirmation happens
  client-side (02's page); the **webhook** `setup_intent.succeeded` (03) creates the local
  row from the PaymentMethod details (brand, last4 → `display_label` "Visa ···4242") —
  never the client callback, same discipline as payments.
- `is_default`: one per contact **per provider account** (partial unique); first saved
  method auto-defaults.
- Detach: `DELETE /api/payment-methods/{id}` → Stripe detach best-effort + local
  `archived_at` (archive-only; a method referenced by a contract's autopay refuses with a
  pointer to change autopay first — 04 owns that reference).

## Panel surface

Contact detail gains **Payment methods** card: saved cards (brand icon, label, default
badge, added date), default toggle, remove with confirm, and **Add card** → generates a
save-card link via 02's page (operator sends it; no card entry in the operator panel —
keeps the panel out of SAQ-A-EP scope). i18n `contacts.paymentMethods.*`; es: *Métodos de
pago*, *Tarjeta predeterminada*.

## Invariants

- Card data never stored/transited server-side beyond Stripe ids and display metadata.
- Local rows created from webhooks only (invariant 11 spirit applied to instruments).
- Archive-only; one default per contact per account.

## Acceptance criteria

- [ ] Customer find-or-create idempotent under race; one per contact per account.
- [ ] SetupIntent endpoint returns secret+key for the contract's entity account.
- [ ] `setup_intent.succeeded` (03 harness) creates the row with correct label/default;
      client callback path creates nothing.
- [ ] Detach archives locally, refuses while autopay-referenced.
- [ ] Panel card lists/defaults/removes; add-card produces a working 02 link.

## Tests required

| Test | Asserts |
|---|---|
| `StripeCustomerTest::find_or_create_race_safe` | Unique-index recovery |
| `PaymentMethodTest::created_only_via_webhook` | Callback writes nothing |
| `PaymentMethodTest::default_per_account_semantics` | Partial unique + auto-default |
| `PaymentMethodTest::detach_archive_and_autopay_guard` | Refusal path |
