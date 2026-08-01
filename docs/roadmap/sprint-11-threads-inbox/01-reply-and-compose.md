# S11-01 — Reply & compose pipeline

## Context

The write half: replying in-thread (email/SMS) and starting a new conversation from a
contact. Everything routes through the S10 senders — the composer is a client of the
same pipeline playbooks use, which is precisely why suppression, provenance, and
threading come free.

## Scope

**In:** reply endpoint, new-thread compose, attachment upload, template + token
insertion for replies, sender-identity resolution + honesty rule, suppression
pre-flight surfacing, per-channel composer rules. **Out:** call-back (S12), WhatsApp,
scheduled sends (noted), rich-text editor beyond basic (bold/italic/links v1 — the
builder work is S13).

## Endpoints

```
POST /api/inbox/threads/{id}/reply
    email: { body_html?, body_text, attachment_ids?: [..], email_template_id? }
    sms:   { body_text }
POST /api/inbox/compose        // new thread
    { contact_id, channel, subject? (email), body…, attachment_ids? }
POST /api/inbox/attachments    // multipart staging → {id}; linked on send, orphans swept daily
GET  /api/inbox/threads/{id}/compose-context
    → { from_identity: {address|number, label} | null,
        suppression: {scope, reason} | null,
        templates: [{id, name}] (email),
        tokens: [friendly token vocabulary] }
```

## Behaviour

- **Reply = `EmailSender`/`SmsSender`** with `SendContext(source: manual, class:
  transactional, employee causer)`. Email replies set `In-Reply-To`/`References` from
  the thread's latest inbound Message-ID (stored in threading_evidence — verify the
  adapters pass custom headers; Postmark/Brevo both support it; this is what makes
  *their* client thread *our* reply) and reuse the thread subject (`Re:` prefixed once).
  The message lands in the same thread by construction (00's resolver given the thread
  id explicitly — replies never re-resolve).
- **Identity honesty (the README rule).** `compose-context` resolves the from-identity:
  thread → contact's most relevant contract → site → `site_sender_identities`, else the
  company account default. Null → composer disabled with the configure-CTA. The
  resolution is server-side and returned for display — the operator sees *as whom* they
  speak before typing.
- **Suppression pre-flight.** `compose-context.suppression` surfaces the state; the
  composer shows the warning ("address suppressed: hard bounce on 12 Jan") and — since
  manual replies are transactional — allows send when scope is `marketing`, blocks with
  explanation when `all`. The sender remains the enforcement (this is display); the
  test asserts UI-state and sender agree.
- **Templates + tokens.** Email reply offers `EmailTemplate` select → rendered through
  `EmailTemplateRenderer` with the thread's contact/contract context; token menu
  inserts `{{contact.first_name}}`-style handles resolved at send (same vocabulary as
  playbooks — one `TokenResolver`, surfaced twice).
- **SMS rules.** Char counter with segment math (GSM-7 vs UCS-2 detection — a naive
  160 count lies the moment an operator types "€"); no attachments; template-less v1.
- **Attachments.** Staged upload (private disk, caps from S10 config), linked on send,
  daily orphan sweep command.

## Acceptance criteria

- [ ] Email reply threads correctly on the *recipient's* side (header fixture asserts
      In-Reply-To/References present in the provider payload) and lands in the same
      thread locally.
- [ ] Identity: per-site resolution shown and used; null-identity disables with CTA;
      wrong-site regression fixture (two sites, two identities, thread bound to B —
      reply must not use A).
- [ ] Suppression matrix in the composer matches sender behaviour exactly.
- [ ] Template render in-composer equals sent output; tokens resolve; SMS segment
      counter correct for GSM-7 and UCS-2 fixtures.
- [ ] Attachments upload/link/sweep; oversize rejected at staging with the S10 caps.
- [ ] Every sent reply shows `source: manual` + employee in the conversation.

## Tests required

| Test | Asserts |
|---|---|
| `ReplyTest::headers_thread_both_sides` | The References contract |
| `IdentityTest::per_site_resolution_and_null_disable` | Honesty rule |
| `ComposerSuppressionTest::display_equals_enforcement` | No divergence |
| `TemplateTokenTest::render_parity` | Composer = wire |
| `SmsSegmentTest::gsm7_ucs2` | The € case |
| `AttachmentTest::stage_link_sweep_caps` | Lifecycle |
