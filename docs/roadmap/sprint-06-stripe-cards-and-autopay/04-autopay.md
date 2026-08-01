# S06-04 — Autopay

## Context

The last mile: a billing run produces charges; autopay collects them off-session from the
contract's stored card the same day. Per architecture §2, autopay is **per contract**
(`payment_method_id` + `autopay_enabled`) — one tenant may autopay one unit and pay the
other by link.

## Scope

**In:** contract autopay columns + management endpoint/UI, `autopay_attempts` table, the
collection job (post-run + scheduled sweep), one-button manual retry, attention surfaces.
**Out:** automatic retry ladders / dunning escalation (S07 consumes `failed` attempts;
building retries twice is the trap named in the sprint README), SCA-challenge tenant
notification (comms phase — the failure is recorded with `requires_action` code).

## Schema changes

```sql
ALTER TABLE contracts ADD COLUMN payment_method_id BIGINT NULL REFERENCES payment_methods(id);
ALTER TABLE contracts ADD COLUMN autopay_enabled BOOLEAN NOT NULL DEFAULT false;

CREATE TABLE autopay_attempts (
    id BIGSERIAL PRIMARY KEY,
    contract_id BIGINT NOT NULL REFERENCES contracts(id),
    payment_method_id BIGINT NOT NULL REFERENCES payment_methods(id),
    charge_ids JSONB NOT NULL,
    amount NUMERIC(10,2) NOT NULL,
    currency CHAR(3) NOT NULL,
    stripe_payment_intent_id VARCHAR(64) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
        -- pending | succeeded | failed
    failure_code VARCHAR(64) NULL,
    decline_code VARCHAR(64) NULL,
    failure_message TEXT NULL,
    triggered_by VARCHAR(16) NOT NULL,     -- billing_run | sweep | manual
    billing_run_id BIGINT NULL REFERENCES billing_runs(id),
    attempted_at TIMESTAMPTZ NOT NULL,
    resolved_at TIMESTAMPTZ NULL,
    created_at TIMESTAMP
);
CREATE INDEX aa_contract_idx ON autopay_attempts (contract_id, status);
CREATE UNIQUE INDEX aa_open_idx ON autopay_attempts (contract_id) WHERE status = 'pending';
```

## Behaviour

**Enable** (`PUT /api/contracts/{id}/autopay` `{ enabled, payment_method_id? }`): method
must belong to the contract's contact, be `stripe_card`, unarchived, and its provider
account must match the contract's entity (the cross-entity card trap: a tenant's card
saved under entity A cannot pay entity B's contract — validate and explain; the fix is
saving the card once per entity via 02). Default = the account's default method. Tier-3
activity — autopay consent is an auditable fact.

**Collection job** (`autopay:collect`, invoked by the run engine after each billing run
for contracts it billed, plus an hourly sweep for anything due-and-enabled it missed —
e.g. autopay enabled after this morning's run):

Per eligible contract (enabled + method + open due charges + no `pending` attempt — the
partial unique enforces single-flight):

1. Insert `pending` attempt (charge set = open due charges, same-currency rule as 02).
2. Create off-session PI: customer + method, `off_session=true`, `confirm=true`,
   metadata `{autopay_attempt_id}`, minor units.
3. Immediate API decline → attempt `failed` + codes synchronously; otherwise the webhook
   (03) resolves it. `requires_action` off-session counts failed (`authentication_required`).
4. Errors isolate per contract (S05 posture); Tier-1 throughout.

**Manual retry:** button on a failed attempt → new `manual` attempt through the same
path (single-flight still applies). No automatic re-attempts — S07 owns cadence.

## Panel surface

Contract Billing card: **Autopay** block — toggle, method selector (contact's eligible
cards + "add card" shortcut into 02's save-card link), status line (last attempt result +
date, next collection = next bill date). Failed state renders an amber banner with the
human decline reason and Retry. Billing runs detail (S05-04) items gain an autopay column
(collected / failed / off). A global attention chip on Billing → Overdue: "N contracts
with failed autopay" filtering the list — the pre-S07 stand-in for dunning. i18n
`billing.autopay.*`; es: *Pago automático*, *Reintentar cobro*.

## Invariants

- 11 rail A — success only via webhook; the synchronous path may only record *failure*.
- 5 — "next collection" displayed, never stored; single-flight via partial unique, not a
  status column on contracts.
- 34 — entity match validated through the resolver, no scoping.

## Acceptance criteria

- [ ] Run → billed contracts with autopay get one attempt each; webhook success lands
      payment + allocations against exactly the run's charges; attempt/request states flip.
- [ ] Immediate decline and webhook-async decline both record codes; no ledger rows.
- [ ] Single-flight: sweep + run racing produce one attempt.
- [ ] Cross-entity card refused with the explanatory message; archived method blocks
      enable and detach-guard (01) holds.
- [ ] Sweep collects a contract enabled after the morning run, same day.
- [ ] Attention chip counts failed-autopay contracts; retry button works and audits.
- [ ] End-to-end demo fixture: seed → `billing:run` → `autopay:collect` → fake webhook →
      contract fully paid, invoice paid-chip green. Name it `DemoSpineTest`.

## Tests required

| Test | Asserts |
|---|---|
| `AutopayTest::run_then_collect_then_webhook_settles` | The spine |
| `AutopayTest::single_flight_under_race` | Partial unique |
| `AutopayTest::declines_recorded_no_money` | Both sync + async |
| `AutopayTest::cross_entity_card_refused` | Resolver validation |
| `AutopayTest::sweep_catches_late_enables` | Hourly path |
| `DemoSpineTest::sign_bill_collect_green` | The sprint's exit, executable |
