# S15-01 — Provider accounts & Sensorberg adapter

## Context

The world-facing half, fifth application of the house adapter architecture. One new
wrinkle the previous four didn't have: **credential modes** — how a tenant physically
authenticates (app invite vs PIN code) varies by provider *and* by the client's
installed hardware, so the adapter declares its capabilities and everything upstream
adapts rather than assumes.

## Scope

**In:** `access_provider_accounts` (house pattern), `AccessProvider` interface,
Sensorberg adapter, point discovery (pull the provider's lock/gate list for 04's
mapping UI), inbound event webhook, `FakeAccessProvider` + seam test.
**Out:** reconciliation (02), mapping UI (04), visitor/temporary access (deferred,
recorded — the model supports it later via short-lived desired-grants).

## ⚠ Sandbox-first rule (the README risk, operationalized)

Before writing the adapter body: obtain Sensorberg sandbox/API access and confirm —
(1) auth scheme, (2) how access is granted (app invite by email? code assignment? both,
per hardware?), (3) whether grants are per-lock or per-group, (4) webhook event
catalogue for door open/denied. The interface below flexes for the likely answers;
where reality differs, **the adapter absorbs it and this file gets corrected in the
same PR** (the S04 AEAT rule, adapter edition). The one-day estimate assumes answers
in hand; chasing access is calendar time, not sprint time — start today.

## Schema changes

```sql
CREATE TABLE access_provider_accounts (
    -- the esign_provider_accounts shape verbatim: provider, display_name,
    -- credentials (encrypted array per credentialFields), webhook_token,
    -- webhook_state, status, last_error, is_active
);
CREATE TABLE access_webhook_events ( -- the idempotency shape verbatim );

CREATE TABLE access_events (
    id BIGSERIAL PRIMARY KEY,
    access_point_id BIGINT NULL REFERENCES access_points(id),  -- NULL = unmapped point
    contact_id BIGINT NULL REFERENCES contacts(id),            -- resolved via grant/credential
    access_grant_id BIGINT NULL REFERENCES access_grants(id),
    event_type VARCHAR(16) NOT NULL,        -- granted | denied
    occurred_at TIMESTAMPTZ NOT NULL,
    provider_credential_ref VARCHAR(128) NULL,
    raw JSONB NOT NULL,
    created_at TIMESTAMP
);
CREATE INDEX ae_point_time_idx ON access_events (access_point_id, occurred_at);
CREATE INDEX ae_contact_idx ON access_events (contact_id, occurred_at);
```

## Behaviour

**Interface.**

```php
interface AccessProvider {
    public function credentialFields(): array;
    public function verify(): void;
    public function credentialModes(): array;      // ['app_invite'] | ['pin'] | both
    public function listPoints(): array;           // [{provider_point_id, label, kind_hint}]
    public function grant(GrantSpec $spec): GrantRef;
        // spec: point ref, person {name, email, phone}, mode, metadata (contract id)
    public function revoke(string $grantRef): void;
    public function listGrants(?string $pointRef = null): array;   // drift-check input (02)
    public function parseWebhook(array $payload): AccessEvent;
}
```

`grant` in `app_invite` mode invites the contact's primary email (missing → the
actionable-422 posture at the call site in 02); `pin` mode returns the code in
`GrantRef` — **stored encrypted on the grant row, shown once to the operator, resent
via the standard senders only** (a PIN in a plain SMS through our own pipeline is
fine; a PIN in a log line is not — no Tier-1 payloads may carry it, assert with a
log-scrubbing test).

**Events.** Webhook route/idempotency per the house pattern. Parsed events resolve:
point by `provider_point_id` (unmapped → stored with NULL point + attention counter —
the client added a lock nobody mapped), contact via the grant ref or credential ref
(unresolved → NULL contact, raw kept). `denied` during an active suspension or
overlock additionally writes a contact-timeline Interaction ("Access denied at Unit
AL6-06 door") — the dispute evidence, cheap here, priceless later.

**Settings.** The S14 Integrations section gains Access control: the standard
connect card + a "Discovered points" count with staleness (feeds 04's mapping).

## Acceptance criteria

- [ ] Connect/verify/credential discipline; modes surfaced; points discoverable and
      cached with refresh.
- [ ] Fake-adapter grant/revoke/list round trip; PIN storage encrypted, shown-once,
      log-scrubbed (the assert).
- [ ] Events: idempotent, unmapped-point and unresolved-contact both stored-not-lost
      with attention counters; the denied-during-restriction Interaction lands.
- [ ] Sensorberg adapter against sandbox: the manual checklist item (gated on access,
      the S04/S14 idiom); automated coverage on the fake.
- [ ] `FakeSecondProvider` seam test — changes confined to adapter + registry.

## Tests required

| Test | Asserts |
|---|---|
| `AccessAccountTest::discipline_modes_points` | The house rules + wrinkle |
| `PinHandlingTest::encrypted_shown_once_never_logged` | The scrub |
| `AccessEventTest::idempotent_unmapped_unresolved_denied_interaction` | Event posture |
| `AccessSeamTest::fake_second_provider` | Fifth proof |
