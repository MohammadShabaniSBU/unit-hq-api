# S13-04 — Composer & playbook integration

## Context

Where the sprint becomes visible: the inbox WhatsApp composer that knows what time it
is, template pickers with variable fill, the `send_whatsapp_template` playbook action,
and SMS templates reaching their pickers. Everything integrates surfaces built in
00–03 and S9–S11 — this task writes almost no new domain logic, which is the
architecture working.

## Scope

**In:** session-aware WA composer, template picker + variable-fill UX (composer and
playbook step editor), `send_whatsapp_template` handler + fixture, SMS template
pickers (inbox composer + playbook sms steps + S12 entry sheets), debt/lead category
enforcement, es sweep + docs. **Out:** WA media composing, campaign/bulk sends,
per-enrolment window strategies beyond the documented behaviour.

## Behaviour

**Inbox WA composer** (threads with `channel = whatsapp`): the S11 composer grows a
mode header driven by the thread's `whatsapp_window` payload — **open**: countdown
chip ("free replies for 6h 12m"), plain composer as SMS; **closed**: the input
replaced by *Choose a template* → picker (approved only, locale-laddered, grouped by
name, category badges) → variable-fill form (each variable: token_default pre-resolved
against the thread's contact/contract *shown as the actual value* with an edit
affordance — the operator sees "Marcus" not `{{contact.first_name}}`) → phone-frame
preview → send. Suppression/consent floor surfaced as in email (display mirrors
enforcement, the S11 rule).

**Playbook action `send_whatsapp_template`** — params `{whatsapp_template_name,
variable_tokens: {1: token…}}` (name not id: resolution picks the approved
locale-right row at *send time* per 03's ladder). Handler: consent floor → resolution
→ token-resolve variables → `sendTemplate` → provenance + Interaction as ever;
non-approved/no-channel/suppressed → skip-with-reason (three distinct reasons — the
run log must distinguish "no WhatsApp" from "template not approved"). **Category
enforcement:** debt kind permits `utility` templates only; lead kind `marketing` or
`utility` — validated at playbook save *and* at send (the template's category can
change via clone-cycles; send-time is the truth). Harness fixture
`wa_template_debt_step` joins the library (coverage law).

**Session-aware playbook subtlety, documented not built:** playbook template sends
may *open* nothing (only inbound opens windows) — a tenant replying to a dunning
template opens the window, and the *operator* continues free-form in the inbox. The
enrolment doesn't react to replies (the standing reply-exit deferral). One paragraph
in the debt page copy manages the expectation.

**SMS templates**: the 00 families with `channel = sms` appear in the inbox SMS
composer, the S12 entry sheets, and playbook `send_sms` params
(`template_family_id` XOR inline body — the S03 XOR idiom); segment counter runs on
the resolved preview.

## Acceptance criteria

- [ ] Window-open and window-closed composer states render and behave per 02's
      boundary; the countdown is honest (server `closes_at`, client ticks).
- [ ] Variable fill shows resolved values, edits persist per-send, preview equals
      wire (fixture).
- [ ] Playbook WA step: happy path + all three skip reasons distinct in the run log;
      category enforcement at save and send; the harness fixture green.
- [ ] SMS template XOR in all three surfaces; segment count on resolved content.
- [ ] Locale ladder end-to-end: es-contact debt step sends the es-approved template,
      fr-contact falls to the logged any-approved.
- [ ] Docs: `06-communications.md` gains WhatsApp reality; `es` sweep of the whole
      sprint's strings.

## Tests required

| Test | Asserts |
|---|---|
| `WaComposerTest::window_states_and_variable_fill` | The UX contract, API-side |
| `WaActionTest::three_skips_and_category_gates` | Run-log distinctness |
| Harness `wa_template_debt_step` | Coverage law |
| `SmsTemplateTest::xor_three_surfaces_segments` | The rider lands |
| Panel manual script | Countdown, picker, preview-equals-sent, es review |
