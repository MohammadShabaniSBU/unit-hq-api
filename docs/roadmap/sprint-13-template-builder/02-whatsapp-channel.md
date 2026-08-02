# S13-02 — WhatsApp channel foundation

## Context

The channel itself: sending (session + template modes), inbound receipt, the 24-hour
window computation, and the consent floor — all through the S10 seams, which is the
point: WhatsApp is a third proof of the adapter architecture, with one genuinely new
concept (the session window) that lives in *our* domain logic, provider-agnostic.

## Scope

**In:** capability interfaces (`SendsWhatsApp` — session text + template send;
delivery events via the existing interface), adapter #1 per the kickoff decision,
inbound → S10 store (threading by `(contact, wa number)` like SMS), window
computation, opt-in floor, `Channel::WhatsApp` implemented, sender identity (the WABA
number) per the existing identity model. **Out:** the template registry/manager (03),
composer UI (04), rich media messages (text v1; media recorded), opt-in *ceremony*
(the floor is: an existing whatsapp contact-channel + no suppression; collection UX
deferred with the standing consent entry).

## Behaviour

- **Window.** `WhatsAppWindow::isOpen(MessageThread $t): bool` =
  `$t->last_inbound_at !== null && now() < last_inbound_at + 24h` — computed, never
  stored (README rule). Exposed on the thread payloads (00's inbox API extends
  naturally: `whatsapp_window: {open, closes_at}|null` on wa threads) so 04's
  composer and the playbook handler share one truth.
- **Send modes.** `SendsWhatsApp::sendSession(text)` — refused by *our* code when the
  window is closed (the provider would refuse anyway; failing locally gives the
  translatable reason) — and `sendTemplate(templateRef, variables[])` — allowed
  anytime to an opted-in number, refused when the referenced registry row (03) isn't
  `approved`. Both land as messages (`source`/provenance as ever), statuses via the
  existing delivery pipeline (WhatsApp adds `read` — extend the status lattice rank;
  the S10 forward-only rule absorbs it).
- **Inbound.** Provider webhook → S10 inbound path: thread find-or-create on the
  number, `last_inbound_at` bump (= window opens — no extra write), unread, triage
  for unknown numbers, STOP-equivalents (Meta's own opt-out events + literal "STOP")
  → suppression `all` on the wa channel value.
- **Identity.** The WABA number registers as a sender identity (site-scoped like SMS
  numbers); resolution through the existing ladder. One WABA v1; multi-number is a
  data-model non-event later (identities already scope).
- **Consent floor.** Sending requires: contact has a `whatsapp` channel value (their
  number, explicitly recorded — *having a phone is not WhatsApp opt-in*; the channel
  row's existence is the floor's proxy for consent, stated in the contact UI helper
  text), not suppressed, and for `marketing`-class sends the S10 scope rules apply
  unchanged.

## Acceptance criteria

- [ ] Session send inside the window delivers + threads; outside refuses locally with
      the reason; the boundary is exact (fixture at 23:59:59 vs 24:00:01).
- [ ] Template send bypasses the window, refuses non-approved refs (03's state
      consulted), lands with provenance.
- [ ] Inbound opens the window (thread payload flips), threads, triages unknowns,
      opt-out suppresses.
- [ ] `read` status ranks above delivered, forward-only holds.
- [ ] Phone-without-whatsapp-channel refused with the consent-floor message; the
      adapter seam proof: pipeline diffs confined to adapter + the window/lattice
      additions (architecture test, Sinch-style).

## Tests required

| Test | Asserts |
|---|---|
| `WindowTest::computed_boundary_exact` | Never stored, second-precise |
| `WaSendTest::session_vs_template_matrix` | Window × approval × consent |
| `WaInboundTest::opens_window_threads_triages_optout` | The S10 path, third proof |
| `StatusLatticeTest::read_rank` | Forward-only extended |
| `SeamTest::diffs_confined` | Architecture |
