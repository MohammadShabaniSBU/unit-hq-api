# S10-02 — Inbound receipt & threading

## Context

The half that makes it an inbox rather than an outbox: replies arrive. Postmark inbound
email (full MIME with attachments) and Twilio inbound SMS land as messages, threaded to
the right conversation, with the unmatched-sender case handled as workflow rather than
data loss.

## Scope

**In:** inbound endpoints/routing (reusing the per-account webhook token where the
provider shares it; separate inbound-configured routes where not — Postmark inbound is
its own server/webhook), MIME/payload parsing, sanitization + attachment storage,
inbound threading, contact matching + triage queue, thread rollups (unread), Brevo
inbound noted per capability.
**Out:** the Inbox UI (S11 — but the triage queue's *data* is here), auto-replies/OOO
loop protection beyond basic (see risks), WhatsApp.

## Behaviour

**Email (Postmark inbound).** Configure per-account inbound webhook (the
`AutoRegistersWebhooks` idiom where the API supports it; else pasteable URL as the
existing pattern). Parse: text + HTML (sanitize at write — the 00 invariant), headers
into `threading_evidence` (`Message-ID`, `In-Reply-To`, `References`), attachments →
size-capped (config, default 10 MB each / 25 MB total; over-cap recorded as a stub row
`oversize = true`, content dropped, honest in UI) → private disk.

**Threading (inbound side of the 00 resolver).** Priority: (1) `In-Reply-To`/
`References` matching a stored outbound `provider_message_id`/Message-ID → that thread;
(2) normalized-subject + contact match → most recent such thread; (3) new thread. SMS:
the `(contact, number)` thread, always. Evidence stored; **re-threading endpoint**
(`POST /api/messages/{id}/move-thread`) lands now with the audit trail, per the README
risk — S11 gets UI, the operation exists first.

**Contact matching.** By channel value against `contact_channels` (normalized email /
E.164). Multiple contacts sharing a channel: newest-activity contact wins,
`threading_evidence.ambiguous = true`. **No match:** do not create contacts silently —
insert into the triage queue: a thread with `contact_id NULL` is forbidden (schema), so
unmatched messages park on a `comms_triage` table (payload, parsed sender, channel,
message-shaped preview) with resolve actions (attach to existing contact → creates the
message properly; create new contact then attach; discard). Marketing spam dies in
triage, not in the CRM.

**Unread + rollups.** Inbound increments `unread_count`, sets `last_inbound_at`; an
`InboundMessageReceived` domain event fires (S11's realtime hook; also the future
lead-chase "replied" exit input — note it, don't wire it).

**Loop safety (floor).** Detect `Auto-Submitted`/`X-Autoreply` headers → message stored,
flagged `auto_generated`, no unread increment. Full loop protection matters when
auto-*responses* exist (not yet).

## Acceptance criteria

- [ ] A reply to a playbook email threads onto the originating thread via References;
      a cold email from a known contact threads by subject/new correctly; SMS reply
      lands on the number thread.
- [ ] Attachments stored privately with caps; oversize honest-stubbed; HTML sanitized
      (fixture with script/onload/external-img asserts stripping).
- [ ] Unknown sender → triage row; all three resolve actions work; nothing creates
      contacts implicitly.
- [ ] Replay of the same inbound payload is a no-op (provider id unique from 00).
- [ ] Unread/rollups correct; auto-generated flagged without unread.
- [ ] Recorded real payload fixtures: Postmark inbound (multipart + attachment),
      Twilio SMS.

## Tests required

| Test | Asserts |
|---|---|
| `InboundThreadingTest::references_subject_new_priority` | The ladder |
| `InboundTest::sanitization_and_attachment_caps` | Untrusted input |
| `TriageTest::unmatched_flow_three_resolutions` | Workflow not loss |
| `InboundTest::idempotent_and_auto_generated` | Replay + loops |
| `RethreadTest::move_endpoint_audited` | The supported operation |
