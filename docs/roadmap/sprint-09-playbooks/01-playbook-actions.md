# S09-01 — Actions: SMS, notice, templated email

## Context

Three handler gaps between what playbooks promise and what the engine can execute:
SMS sending (SmsSender exists, no handler), notice recording (the debt ladder's paper
trail, S07's table), and email steps referencing an `EmailTemplate` rather than only
inline content. Each is thin because its heavy half already exists.

## Scope

**In:** `action.send_sms`, `action.record_notice`, `send_email` template-reference
params, the interim send floor from the sprint README, harness fixtures for each.
**Out:** WhatsApp (template-approval world — comms phase), delivery-status feedback into
runs (needs inbound webhooks wired to steps — comms phase; provenance ids are stored now
so it back-fills), any consent model.

## Behaviour

**`action.send_sms`** — params `{ "body": "...", "tokens": true }`. Resolve tokens via
`TokenResolver` against the run subject chain (delinquency→contract→contact /
deal→contact); resolve recipient = contact's **primary phone channel**; missing →
step `succeeded` with `detail.skipped_reason = no_channel` (a sequence must survive a
contact without a phone — the next step still fires; "skipped-with-reason inside a
succeeded step" keeps run semantics simple and honest, mirror it in send_email). Send
via `SmsSender` (provider resolution as shipped); store `provider_message_id` +
`communication_account_id` on the step detail **and** write the `Interaction` row —
the CRM timeline must show playbook sends indistinguishably from manual ones.

**`send_email` upgrade** — params gain `{ "email_template_id": n }` XOR inline
`{ subject, body }`; template path renders through the existing template machinery with
the same token context. Same primary-channel + skip semantics + Interaction write
(verify the existing handler already does the Interaction/provenance part — align if
not, it predates the comms rules).

**`action.record_notice`** — params `{ "notice_type": "payment_reminder|overdue|final_demand|retention" }`.
Valid only when the subject chain reaches a contract (kind validation, 00, restricts it
to debt playbooks). Writes `contract_notices` (unsent — mark-sent stays the S07 manual
flow *unless* the same playbook step also sent the email: when a debt step pairs
`send_email` with `record_notice: true` param sugar — see 02 — the notice row records
`sent_at/channel` from the send). Links into the delinquency case timeline via a
`delinquency_steps` row (`trigger = ladder`? No — new trigger value `playbook`, so the
case timeline distinguishes engine-ladder vs playbook actions; small enum addition +
timeline rendering note for 04… actually S07's panel — add the value, render generic).

## Fixtures (coverage law)

`sms_send_and_skip_no_channel`, `email_template_reference`,
`record_notice_debt_chain` — each exercising the happy path + the skip/validation edge.

## Acceptance criteria

- [ ] SMS sends through the resolved provider with tokens rendered; provenance on step +
      Interaction written; missing phone skips-with-reason and the sequence continues.
- [ ] Email template path renders identically to the template's manual send; inline path
      unchanged; XOR validated.
- [ ] record_notice writes notice + case-timeline row with `playbook` trigger; rejected
      at compile for lead playbooks.
- [ ] All three fixtures green; `HandlerCoverageTest` green.
- [ ] Existing send_email runs (pre-upgrade params) still execute (param
      backward-compat test).

## Tests required

The three fixtures + `SendFloorTest::primary_channel_only_and_interaction_written` +
`RecordNoticeTest::kind_restriction_and_sent_pairing` +
`SendEmailCompatTest::legacy_params`.
