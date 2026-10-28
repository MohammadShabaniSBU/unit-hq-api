# S06-00 — Re-home Stripe to legal entities

## Context

The working per-site Stripe code was built before legal entities existed. The merchant of
record is the entity (its NIF signs the Stripe account); credentials, webhook secrets and
inbound routes belong there. This task moves them — porting the proven flows (verify via
`GET /v1/balance`, webhook auto-registration behind `PublicUrlGuard`, blank-unchanged,
`DecryptException → credentials_unreadable`) with minimal rewriting.

## Scope

**In:** `payment_provider_accounts` (+ `account_token`), port of connect/verify/webhook/
rotate/disconnect, per-account inbound route, `stripe_webhook_events` re-keying, removal of
the per-site table/controllers/routes, Settings UI move.
**Out:** processing events (03), any non-Stripe provider (schema is provider-generic per
the architecture doc; only `stripe` validates).

## Schema changes

Per `architecture-payments-and-fiscal.md` §2, plus the routing token:

```sql
CREATE TABLE payment_provider_accounts (
    id               BIGSERIAL PRIMARY KEY,
    legal_entity_id  BIGINT NOT NULL REFERENCES legal_entities(id),
    provider         VARCHAR(32) NOT NULL DEFAULT 'stripe',
    display_name     VARCHAR(128) NOT NULL,
    publishable_key  VARCHAR(255) NULL,
    secret_key       TEXT NULL,               -- encrypted cast
    webhook_secret   TEXT NULL,               -- encrypted cast
    webhook_endpoint_id VARCHAR(64) NULL,
    provider_account_id VARCHAR(64) NULL,     -- provider's own id (Stripe acct_…), metadata
    account_token    VARCHAR(64) NOT NULL,    -- crypto-random routing secret, invariant 6 idiom
    status           VARCHAR(16) NOT NULL DEFAULT 'disconnected',
    last_error       TEXT NULL,
    is_active        BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE UNIQUE INDEX ppa_token_idx ON payment_provider_accounts (account_token);
CREATE UNIQUE INDEX ppa_entity_active_idx
    ON payment_provider_accounts (legal_entity_id, provider) WHERE is_active;

ALTER TABLE stripe_webhook_events ADD COLUMN payment_provider_account_id BIGINT NULL
    REFERENCES payment_provider_accounts(id);
-- uniqueness becomes per-account: (payment_provider_account_id, stripe_event_id)
-- (two entities' accounts may legitimately emit the same event id)

DROP TABLE site_stripe_settings;  -- after code port; no live data
```

### `account_token` vs `provider_account_id` — two columns, two jobs

`account_token` is the **inbound routing secret**: it selects the signing secret before any
trust exists, is unguessable (probing yields nothing), rotatable if a URL leaks, and
provider-neutral. `provider_account_id` is **metadata**: fetched during verify
(`GET /v1/account`), displayed ("connected to acct_1ABC…"), and used as a sanity check that
the pasted keys belong to the account the operator intended — surface a warning if a key
rotation resolves to a *different* provider account than stored. Never route by it; never
put it in a URL.

### Provider-genericity (GoCardless-readiness)

The row shape already fits bank-debit providers: their access token → `secret_key`, signing
secret → `webhook_secret`, `publishable_key` null. The partial unique
`(legal_entity_id, provider) WHERE is_active` deliberately permits **Stripe and a debit
provider active simultaneously** on one entity — cards + SDD coexisting. What a second
provider actually needs is rail semantics (mandates, delayed confirmation per the
rail-specific invariant 11), which is future adapter work mirroring the comms
capability-interface design. The rule *this* sprint must respect: Stripe-specific
assumptions live only in the client class and task 03's event handlers — nothing else may
branch on `provider === 'stripe'`, so the seam stays clean for the adapter refactor.

## Implementation notes

- Verify step additionally calls `GET /v1/account`, stores `provider_account_id`, and
  warns (status note, not failure) when a rotation resolves to a different account.
- Port `SiteStripeSettingController` → `LegalEntityStripeController` under the entity
  routes; the verify/webhook-create/disconnect logic transfers with the model swap. Inbound:
  `POST /api/webhooks/stripe/{account_token}` (public) — resolve account, refuse when
  entity archived or account inactive (ack 200, ignore), verify `Stripe-Signature` with
  that account's secret, idempotent insert with `payment_provider_account_id`, dispatch,
  ack fast. The old per-site route dies.
- Public key endpoint moves: `GET /api/legal-entities/{id}/stripe/public-key` **plus** the
  resolution helper the pay page really needs:
  `App\Support\Payments\ProviderAccountResolver::forContract(Contract): PaymentProviderAccount`
  (contract → site → entity → active account; throws `PaymentsNotConfigured` with the
  entity named — the 422 every later task surfaces).
- Credential discipline: invariants 26–27 verbatim (`App\Support\Credentials` helpers,
  Tier-3 create/rotate/remove, masked last-4). Rotation does not recreate the endpoint
  (established rule).
- Update `05-billing-ledger.md`'s Stripe section to per-entity in the same PR — it still
  documents per-site and Cursor reads it.

## Panel surface

Settings → Payments becomes an **entity-keyed** index (one row per entity: status chip,
masked key, connect/manage) replacing the per-site index. Entity detail gains the Stripe
card (paste keys → verify → create webhook → status), reusing the existing form component
with an entity prop. i18n keys move `settings.payments.site.*` → `.entity.*`.

## Invariants

- 26/27 (credentials), 6 (token, never PK), 34 (entity FK read explicitly — the resolver
  is the one blessed traversal).
- New: **Stripe webhook identity is per provider account** — signature secret and event
  idempotency are scoped by `payment_provider_account_id`.

## Acceptance criteria

- [ ] Entity connects: bad secret → `error` + `last_error`; good → `connected` with
      `provider_account_id` stored; webhook registered against the account's URL;
      disconnect deletes remote endpoint best-effort.
- [ ] Key rotation resolving to a different provider account surfaces the mismatch
      warning without blocking.
- [ ] Grep: `provider === 'stripe'` (and equivalents) appears only in the client class
      and webhook handlers — the adapter seam is clean.
- [ ] Inbound verifies per-account; wrong-token 404s; archived entity acks-and-ignores;
      same `stripe_event_id` on two accounts inserts twice, on one account once.
- [ ] `ProviderAccountResolver` walks contract→entity→account and fails loudly when
      unconfigured.
- [ ] `site_stripe_settings` and its routes/controllers are gone; panel shows the entity
      index; docs updated.
- [ ] Seeder: one connected account (fake keys, `connected` forced) per entity for
      downstream tasks.

## Tests required

| Test | Asserts |
|---|---|
| `ProviderAccountTest::connect_verify_status_paths` | Ported behaviour intact |
| `ProviderAccountTest::per_account_event_idempotency` | Cross-account same-id |
| `ProviderAccountTest::archived_entity_acks_ignores` | No processing |
| `ProviderAccountTest::resolver_walks_and_fails_loudly` | `PaymentsNotConfigured` |
| `ProviderAccountTest::credential_discipline` | Mask/blank/Tier-3/decrypt-degrade |
