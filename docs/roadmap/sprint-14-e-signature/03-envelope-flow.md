# S14-03 — Envelope flow & signed storage

## Context

The orchestration: a generated document becomes an envelope, the envelope's events
drive the contract's fate, and the signed artifact lands immutably. This is where
00's completion routine gets its second caller and the race gets its loud edge case.

## Scope

**In:** `esign_envelopes` lifecycle, send/resend/cancel, event handling → contract
completion, signed-PDF + certificate storage with hashes, expiry sweep, the
signed-after-cancel loud path. **Out:** UI placement (04), reminders-to-signer
(the provider's own reminders suffice v1; ours would be an automation recipe —
noted), multi-signer.

## Schema changes

```sql
CREATE TABLE esign_envelopes (
    id BIGSERIAL PRIMARY KEY,
    contract_id BIGINT NOT NULL REFERENCES contracts(id),
    contract_document_id BIGINT NOT NULL REFERENCES contract_documents(id),
    esign_provider_account_id BIGINT NOT NULL REFERENCES esign_provider_accounts(id),
    provider_envelope_ref VARCHAR(128) NOT NULL,
    signer_name VARCHAR(255) NOT NULL, signer_email VARCHAR(255) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'sent',
        -- sent | viewed | signed | declined | expired | cancelled
    decline_reason TEXT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    sent_at TIMESTAMPTZ NOT NULL, viewed_at TIMESTAMPTZ NULL, signed_at TIMESTAMPTZ NULL,
    signed_pdf_path VARCHAR(500) NULL, signed_pdf_sha256 VARCHAR(64) NULL,
    certificate_path VARCHAR(500) NULL,
    created_by BIGINT NULL, created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE UNIQUE INDEX ee_open_idx ON esign_envelopes (contract_id)
    WHERE status IN ('sent','viewed');        -- one live envelope per contract
CREATE INDEX ee_ref_idx ON esign_envelopes (provider_envelope_ref);
```

## Behaviour

**Send.** From an awaiting contract with a draft document: signer defaults to the
contact (billing name where complete; email = primary channel — missing email is a
422 pointing at the contact, the S05 fiscal-blocker posture), expiry default 14 days
(org setting), `createEnvelope` with the stored PDF bytes, document → `sent` (frozen
per 01), envelope row, `Interaction` (the tenant-facing send belongs on the timeline)
+ core activity. **Resend** = cancel provider-side + supersede envelope + new one
against the same frozen document (or a regenerated draft — operator's explicit
choice when the document changed; the picker shows which document each envelope
carried, by hash prefix).

**Events** (via 02's pipeline):

- `viewed` → stamp + activity (the offers `first_viewed` idiom).
- `signed` → the sprint's heart, one transaction: `downloadSigned` → store PDF +
  certificate (private disk) + sha256 → envelope `signed` → **claim the contract
  transition** (00's conditional update) → `ContractSigning::complete()`. Download
  failure retries with the envelope `signed` but completion pending (a
  `completion_pending` flag + sweep — the artifact matters more than latency; the
  contract completes when the bytes are safe, and the panel shows the in-between
  honestly).
- `signed` **after cancel/decline-race**: the claim loses → do NOT complete; envelope
  records `signed` with a `post_cancellation` flag, Tier-3
  `esign.signed_after_cancellation`, attention-surface entry (04) — a human
  conversation, per the README.
- `declined` → reason stored, contract stays awaiting (operator decides: resend or
  cancel), activity + attention.
- `expired` (their event, plus our read-time check as belt) → same posture as
  declined.

**Cancel** (operator, or via contract cancellation): `cancelEnvelope` best-effort,
envelope `cancelled`; the contract-level cancel from 00 calls through here when a
live envelope exists.

**Storage discipline.** Signed PDF + certificate: written once, hash recorded, no
update path (model guard), served via authenticated stream only (the attachments
route idiom), included in the contact's GDPR export, **excluded from redaction
deletion** (legal-retention basis — the S03 invoice-snapshot precedent; note in
`config/redaction.php`).

## Acceptance criteria

- [ ] Full happy path on the fake adapter: send → viewed → signed → bytes stored +
      hashed → completion transaction (00's) → contract active/pending; all stamps
      + activities present.
- [ ] One-live-envelope enforced; resend supersedes with document-choice honesty.
- [ ] Download-failure path: signed-but-pending state, sweep completes when the
      adapter recovers, panel state honest.
- [ ] The race: cancel-then-signed records loudly, completes nothing; signed-then-
      cancel refuses the cancel (claim order both ways).
- [ ] Declined/expired leave awaiting with reasons; missing-email 422 actionable.
- [ ] Storage guards: no update path, authenticated stream, redaction note.

## Tests required

| Test | Asserts |
|---|---|
| `EnvelopeFlowTest::happy_path_to_completion` | The heart, transactional |
| `EnvelopeRaceTest::both_orders` | Claim semantics + loud record |
| `EnvelopeTest::download_failure_pending_sweep` | Bytes before status |
| `EnvelopeTest::resend_supersede_document_choice` | Hash-prefix honesty |
| `SignedStorageTest::immutable_streamed_retained` | Legal artifact rules |
