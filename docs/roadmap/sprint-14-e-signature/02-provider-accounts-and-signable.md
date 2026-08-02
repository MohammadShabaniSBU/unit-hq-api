# S14-02 — Provider accounts & Signable adapter

## Context

The multi-adapter seam, fourth application of the house architecture: capability
interface, per-account credentials with the full discipline, tokened webhook routes,
one concrete adapter. Signable first per the standing decision — **verify their
current API (envelopes, parties, webhooks, document download) at implementation**;
this file specifies shape, not their field names.

## Scope

**In:** `esign_provider_accounts`, `ESignProvider` interface, webhook route +
idempotency, Signable adapter, settings UI. **Out:** envelope orchestration (03 —
this task proves connect/send/receive mechanics with a stub document), any second
adapter (the architecture test stands in for it).

## Schema changes

```sql
CREATE TABLE esign_provider_accounts (
    id BIGSERIAL PRIMARY KEY,
    provider VARCHAR(32) NOT NULL,            -- signable
    display_name VARCHAR(128) NOT NULL,
    credentials TEXT NOT NULL,                -- encrypted array (API key etc. per adapter credentialFields)
    webhook_token VARCHAR(64) NOT NULL,       -- the routing-secret idiom
    webhook_state VARCHAR(16) NOT NULL DEFAULT 'unconfigured',
    status VARCHAR(16) NOT NULL DEFAULT 'disconnected',
    last_error TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE UNIQUE INDEX epa_token_idx ON esign_provider_accounts (webhook_token);
CREATE UNIQUE INDEX epa_active_idx ON esign_provider_accounts (provider) WHERE is_active;
-- one active account per provider; one active provider per install v1 (resolver takes
-- the single active account; per-entity e-sign accounts deferred, recorded — unlike
-- payments, the signing brand is usually operator-wide)

CREATE TABLE esign_webhook_events (
    -- the comms/stripe idempotency shape verbatim: account FK, provider_event_id
    -- (or derived key, documented per adapter), payload, processed, timestamps
);
```

## Behaviour

**Interface.**

```php
interface ESignProvider {
    public function credentialFields(): array;
    public function verify(): void;                       // cheap authenticated call
    public function signatureAnchor(): string;            // 01's block token
    public function createEnvelope(EnvelopeSpec $spec): EnvelopeRef;
        // spec: pdf bytes, title, signer {name, email}, expiry, metadata (contract id)
    public function cancelEnvelope(string $ref): void;
    public function downloadSigned(string $ref): SignedResult;   // pdf bytes + audit/certificate bytes|null
    public function parseWebhook(array $payload): ESignEvent;
        // normalized: envelope_ref, type: sent|viewed|signed|declined|expired|bounced,
        // occurred_at, signer info, decline_reason?
}
```

**Signable adapter** implements it; sandbox/live is credentials, not a mode column
(the standing rule). Webhook route `POST /api/webhooks/esign/{webhook_token}` —
resolve, verify per their scheme, idempotent insert, dispatch, ack fast; inactive
account acks-and-ignores (the S06 posture).

**Settings.** Settings → Integrations → **E-signature** (a new settings section —
access control will join it in S15): provider card, credential form (masked,
blank-unchanged, Tier-3 events — the discipline verbatim), verify button, webhook URL
display + state. i18n `settings.esign.*`.

## Acceptance criteria

- [ ] Connect/verify/error paths with credential discipline; webhook URL shown with
      state.
- [ ] Stub-document round trip against Signable **sandbox**: create → their sandbox
      sign → webhook events land idempotently → signed PDF downloads (manual checklist
      item gated on sandbox credentials, mirroring the S04 prewww item; automated
      tests run on a fake adapter).
- [ ] Replay is a no-op; unknown event types ack + Tier-1; inactive account ignores.
- [ ] Architecture test: a `FakeSecondProvider` registers and round-trips the fake
      flow with zero changes outside adapter + registry.

## Tests required

| Test | Asserts |
|---|---|
| `ESignAccountTest::connect_verify_discipline` | The house rules |
| `ESignWebhookTest::idempotent_unknown_inactive` | The posture triplet |
| `AdapterSeamTest::fake_second_provider` | Multi-adapter proven |
