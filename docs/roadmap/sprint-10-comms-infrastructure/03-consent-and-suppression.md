# S10-03 — Consent & suppression

## Context

The pre-send gate. Two related but distinct mechanisms land: **suppression** (the
network said stop: hard bounce, spam complaint, SMS STOP — mandatory, automatic,
channel-value-scoped) and a minimal **consent class** on sends (transactional vs
marketing) so the gate can be strict where the law is strict without breaking dunning
emails. Full marketing-consent ceremony (double opt-in, per-purpose) is deliberately
deferred to the campaign work — recorded, not smuggled.

## Scope

**In:** suppression store + writers (from 01's `ChannelDeliveryFailed`, STOP keywords,
manual), send classification (`SendContext` gains `class`), enforcement inside the two
senders, playbook skip integration, unsubscribe handling floor (List-Unsubscribe header
+ provider unsubscribe events), panel visibility.
**Out:** double opt-in, preference centre, per-purpose consent records, WhatsApp opt-in
windows (all → campaign/template phase, `10-open-decisions.md`).

## Schema changes

```sql
CREATE TABLE channel_suppressions (
    id BIGSERIAL PRIMARY KEY,
    channel VARCHAR(16) NOT NULL,              -- email | sms
    address VARCHAR(255) NOT NULL,             -- normalized value; suppression follows the
                                               -- address, not the contact — a re-added
                                               -- typo'd email stays suppressed
    scope VARCHAR(16) NOT NULL DEFAULT 'all',  -- all | marketing
    reason VARCHAR(32) NOT NULL,               -- hard_bounce | complaint | stop_keyword
                                               -- | unsubscribed | manual
    source_message_id BIGINT NULL REFERENCES messages(id),
    created_by BIGINT NULL REFERENCES employees(id),
    lifted_at TIMESTAMPTZ NULL,                -- lift, never delete
    lifted_by BIGINT NULL, lift_reason TEXT NULL,
    created_at TIMESTAMP
);
CREATE UNIQUE INDEX cs_active_idx ON channel_suppressions (channel, address)
    WHERE lifted_at IS NULL;
```

## Behaviour

**Writers.** From 01's event: permanent bounce / complaint → `scope all` (complaint) or
`all` (hard bounce — deliverability, not preference). Inbound SMS matching STOP keywords
(`STOP`, `BAJA`, `STOP TODO` — per-locale list in config) → sms `all` + an
auto-acknowledgement is **not** sent (no auto-responses exist; note for later).
Unsubscribe (provider event or List-Unsubscribe post) → email `marketing`. Manual add
from the panel (reason required).

**Classification.** `SendContext.class`: `transactional` (offers, payment requests,
invoices, dunning/debt playbook) | `marketing` (campaigns, lead-chase nurture — pick
per playbook kind: debt = transactional, lead chase = **marketing**, the honest
reading). Class is set by the caller; senders refuse a missing class (compile-time-ish:
the value object requires it).

**Enforcement — in the senders, nowhere else.** Before provider dispatch:
active suppression on the recipient address where `scope = all`, or
`scope = marketing` + `class = marketing` → the send does not happen; a `failed`-status
message records `detail.suppressed_reason` (attempts stay visible — the S06 posture);
playbook handlers already treat non-sends as skip-with-reason, so sequences continue.
`GDPR`: suppression rows survive `contacts:redact` (they key on address, deliberately —
add the note to `config/redaction.php`).

**List-Unsubscribe floor.** Marketing-class emails get `List-Unsubscribe` (+ one-click
POST variant) pointing at a public token endpoint that writes the `marketing`
suppression — required by the major mailbox providers for bulk senders; transactional
mail omits it.

## Panel surface

Contact detail channel rows: suppression badge (reason, date) + lift action
(confirm + reason; Tier-3 — lifting a complaint suppression is an accountable act).
Settings → Communications gains a Suppressions list (search by address, add manual,
lift). Playbook enrolment/step views show `suppressed` skips distinctly from
`no_channel`. i18n `comms.suppression.*`; es: *Dirección suprimida*, *Levantar
supresión*.

## Acceptance criteria

- [x] Hard bounce, complaint, STOP, unsubscribe each write the right scope/reason;
      soft bounce writes nothing.
- [x] Suppressed-all blocks both classes; marketing-scope blocks marketing only —
      dunning email to an unsubscribed (not bounced) address still sends.
- [x] Enforcement lives only in the senders (grep: no caller pre-checks); every caller
      inherits it; playbook sequences continue past suppressed steps.
- [x] Address-keyed: channel deleted and re-added stays suppressed; redaction survives.
- [x] List-Unsubscribe on marketing mail only; the public endpoint writes and is
      idempotent.
- [x] Lift is audited and reversible history (never delete).

Panel badge / Settings suppressions list / i18n remain for the panel surface (out of API test plan).

## Tests required

| Test | Asserts |
|---|---|
| `SuppressionWriterTest::four_reasons_scopes` | The mapping table |
| `EnforcementTest::class_scope_matrix` | 2×2 incl. dunning-passes case |
| `EnforcementTest::sender_only_single_gate` | Architecture grep |
| `SuppressionTest::address_keyed_survives_redact_and_readd` | Persistence semantics |
| `UnsubscribeEndpointTest::token_write_idempotent` | Public floor |
