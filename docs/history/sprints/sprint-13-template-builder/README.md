# Sprint 13 — Template Builders

## Goal

The last first-message ask: **email builder v2** (block-based, multi-language — the
"templates should support multi language" requirement lands here) and the **WhatsApp
channel** built the only way Meta permits: a template *manager* with approval sync and a
session-aware composer, not a free-form canvas. SMS templates ride along cheaply.

## The WhatsApp constraint model (design driver, restated from first planning)

Meta's rules shape everything: outside a **24-hour customer-service window** (opened by
the tenant's inbound message), only **pre-approved templates** with positional variables
may be sent, in an approved language, under a category (utility/marketing/authentication)
that Meta polices. Inside the window, free-form is allowed. So the product is: a template
registry synced with the provider's approval state, a composer that *knows what time it
is*, and variable-fill UX — the builder is a form, the approval is the feature.

## ⚠ Decision required at kickoff — WhatsApp provider

The channel ships behind capability interfaces (`SendsWhatsApp`,
`ManagesWhatsAppTemplates`, delivery events via the existing interface); adapter #1 needs
choosing:

| Option | For | Against |
|---|---|---|
| **Sinch** (default) | Account + adapter seams already live (S10); one vendor for SMS+WA | Their WA API differs from their SMS product — new surface regardless |
| Twilio | Familiar API family | Second credential set; SMS already moved to Sinch-capable |
| Meta Cloud API direct | No middleman, cheapest | Own the WABA onboarding + webhook infra; most work |

Default **Sinch** unless the client's WhatsApp Business Account already lives elsewhere —
ask them; migrating a WABA between providers is painful and their answer overrides
architecture preference. All tasks are written provider-agnostic; only 02's adapter
internals change with the answer. Verify every endpoint shape against current provider +
Meta docs at implementation (the standing rule).

## Exit criteria

- [x] An operator builds a two-language email in blocks, previews desktop/mobile,
      test-sends, and a playbook step to a Spanish-site contact renders the `es`
      variant automatically.
- [x] A WhatsApp template drafts → submits → syncs to approved/rejected (with Meta's
      reason) without leaving the panel.
- [ ] The inbox WhatsApp composer free-texts inside an open session, and outside it
      offers only approved templates with variable fill — the state visibly explained.
- [ ] An inbound WhatsApp message opens a session, threads via the S10 store, and the
      debt playbook can send an approved utility template as a step.
- [ ] Existing email templates migrate into the family/variant model with zero
      rendering changes to in-flight references.

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Template families & locale variants](./00-template-families.md) | 1 day |
| 01 | [Email builder v2](./01-email-builder-v2.md) | 1.5 days |
| 02 | [WhatsApp channel foundation](./02-whatsapp-channel.md) | 1.5 days |
| 03 | [WhatsApp template manager](./03-whatsapp-template-manager.md) | 1 day |
| 04 | [Composer & playbook integration](./04-composer-and-playbook-integration.md) | 1 day |

## Risks

**Email HTML is 2005 forever.** The block renderer emits table-based, inline-styled,
email-safe HTML server-side — no client-framework markup reaching a mailbox. Golden-file
the rendered output per block type; Outlook is the fixture target, not Chrome.

**Approval is asynchronous and vendable.** Meta can approve in minutes or days, reject
with opaque reasons, and *revoke* previously-approved templates. Status is synced state,
never assumed; a playbook step referencing a template that lost approval must skip-with-
reason (the S09 posture), not error the run.

**The 24h window is computed, never stored.** `last_inbound_at` on the thread already
exists (S10); the window is `now < last_inbound_at + 24h` evaluated at send — a stored
`session_open` flag would be invariant-5's mistake wearing a headscarf.

**Category honesty.** Dunning templates are `utility`; lead-nurture is `marketing` —
misdeclaring to dodge Meta's marketing pricing/limits gets WABAs banned. The manager
labels consequences, the debt/lead kinds enforce the mapping (the S10 class discipline,
WhatsApp edition).
