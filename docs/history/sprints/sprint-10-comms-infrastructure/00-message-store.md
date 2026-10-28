# S10-00 — Message store & outbound rewiring

## Context

The canonical tables the Inbox reads. Sends currently scatter their truth across
`Interaction`, `OfferDelivery`, and playbook step details; this task gives every
communication one home with a body, a thread, and a state — and rewires the two senders
so every existing caller (manual, offers, playbooks) flows through it without knowing.

## Scope

**In:** `message_threads`/`messages`/`message_attachments`, threading resolver
(outbound side), `EmailSender`/`SmsSender` writing messages, `Interaction` linkage,
`OfferDelivery` linkage, backfill-free coexistence (old rows stay; store starts now).
**Out:** inbound (02), delivery-state updates (01), read-state/assignment (S11 — columns
land, semantics later), calls (04).

## Schema changes

```sql
CREATE TABLE message_threads (
    id BIGSERIAL PRIMARY KEY,
    contact_id BIGINT NOT NULL REFERENCES contacts(id),
    channel VARCHAR(16) NOT NULL,              -- email | sms | whatsapp | call
    subject VARCHAR(500) NULL,                 -- email; null elsewhere
    channel_key VARCHAR(255) NULL,             -- sms/call: the counterparty number (one thread per contact+number)
    last_message_at TIMESTAMPTZ NOT NULL,
    last_inbound_at TIMESTAMPTZ NULL,
    -- S11 lands semantics; columns now so the Inbox needs no migration:
    assigned_employee_id BIGINT NULL REFERENCES employees(id),
    unread_count SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE INDEX mt_contact_idx ON message_threads (contact_id, channel);
CREATE INDEX mt_inbox_idx ON message_threads (last_message_at DESC);

CREATE TABLE messages (
    id BIGSERIAL PRIMARY KEY,
    message_thread_id BIGINT NOT NULL REFERENCES message_threads(id),
    direction VARCHAR(8) NOT NULL,             -- inbound | outbound
    status VARCHAR(16) NOT NULL,               -- outbound: queued|sent|delivered|opened|clicked|bounced|failed|spam
                                               -- inbound: received
    body_text TEXT NULL,
    body_html TEXT NULL,                       -- sanitized at write
    from_address VARCHAR(255) NOT NULL,
    to_address VARCHAR(255) NOT NULL,
    provider VARCHAR(32) NULL,
    communication_account_id BIGINT NULL REFERENCES communication_accounts(id),
    provider_message_id VARCHAR(255) NULL,
    threading_evidence JSONB NULL,             -- headers/keys the resolver used (02 fills for inbound)
    source VARCHAR(24) NOT NULL,               -- manual | offer | playbook | automation | system
    source_ref JSONB NULL,                     -- e.g. {offer_delivery_id} / {automation_run_id, step}
    sent_at TIMESTAMPTZ NULL,
    created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE INDEX m_thread_idx ON messages (message_thread_id, created_at);
CREATE UNIQUE INDEX m_provider_idx ON messages (provider, provider_message_id)
    WHERE provider_message_id IS NOT NULL;     -- 01/02's reconciliation + idempotency key

CREATE TABLE message_attachments (
    id BIGSERIAL PRIMARY KEY,
    message_id BIGINT NOT NULL REFERENCES messages(id),
    filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(128) NOT NULL,
    size_bytes INTEGER NOT NULL,
    disk_path VARCHAR(500) NOT NULL,           -- private disk
    created_at TIMESTAMP
);

ALTER TABLE interactions ADD COLUMN message_id BIGINT NULL REFERENCES messages(id);
ALTER TABLE offer_deliveries ADD COLUMN message_id BIGINT NULL REFERENCES messages(id);
```

## Behaviour

**Thread resolution (outbound).** `App\Support\Communications\Threading::forOutbound(
contact, channel, {subject|number})` — email: reuse the contact's most recent email
thread when the normalized subject matches (strip `Re:/Fwd:` prefixes) else new thread;
sms: find-or-create by `(contact, channel_key = E.164 number)` — race-safe on a unique
index for the sms/call pair `(contact_id, channel, channel_key)`. Store the decision
inputs in `threading_evidence` even outbound (subject-normalized form) — 02 extends the
same resolver inbound.

**Sender rewiring.** Inside `EmailSender::send` / `SmsSender::send`, after provider
accept: create the message (`status = sent`, provider ids, source from a new explicit
`SendContext` value object the callers pass — offers pass `offer`, playbook handlers
`playbook` with run/step refs; default `manual`), update/create the `Interaction` with
`message_id`, stamp `OfferDelivery.message_id` when in an offer context. Provider
*rejection* still records a `failed` message — the Inbox must show the attempt.
Callers change only by passing `SendContext`; grep every call site.

**Thread rollups.** `last_message_at` maintained on write (touch in the same
transaction); `unread_count` incremented by inbound only (02) — outbound never marks
unread. These are the two S11 hot-path columns; everything else stays computed.

## Invariants

Add to `09`:

> **`messages` is the canonical communication record.** Every send and every receipt
> creates exactly one message; `Interaction` is a linked timeline index, never the body
> store. `(provider, provider_message_id)` is unique — it is the reconciliation key for
> delivery events and the idempotency key for inbound receipt. Bodies are sanitized at
> write; attachments live on the private disk.

## Acceptance criteria

- [ ] Manual, offer, and playbook email+SMS sends each produce message + linked
      Interaction (+ OfferDelivery stamp in offer context); CRM timeline rendering
      unchanged.
- [ ] Subject-based email thread reuse and number-based SMS find-or-create behave,
      race-safe.
- [ ] Provider rejection records a `failed` message.
- [ ] `SendContext` at every call site (grep-audited); playbook step details link the
      message id.
- [ ] Seeder: threads with mixed history per contact so S11 renders from day one.

## Tests required

| Test | Asserts |
|---|---|
| `MessageStoreTest::three_sources_one_shape` | manual/offer/playbook parity |
| `ThreadingTest::email_subject_reuse_and_sms_number_race` | Resolver + unique index |
| `MessageStoreTest::rejection_recorded_failed` | Attempts visible |
| `MessageStoreTest::interaction_and_delivery_linkage` | Index rows point home |
