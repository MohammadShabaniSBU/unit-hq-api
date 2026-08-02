# S12-03 — SMS entry points & docs

## Context

Two-way SMS has worked since S10 and composes since S11 — but only *inside* the inbox.
Half a day of entry points puts "text them" where the operator already is, plus the
sprint's docs debt.

## Scope

**In:** contact-page SMS action, delinquency board/case quick-SMS, recipe docs
(missed-call follow-up), `06-communications.md` + `01-stack.md` updates for
S11/S12 surfaces, es sweep. **Out:** SMS templates (S13 with the builders), bulk SMS
(campaigns, later phase).

## Panel surface

- **Contact page:** header quick action *Send SMS* + per-phone-channel glyph → opens
  a slim compose sheet (01's SMS composer component reused: body, segment counter,
  identity line, suppression state) → sends via `inbox/compose` (channel sms) →
  success links "View in Inbox". No new endpoint — the S11 compose path was built
  for exactly this reuse.
- **Delinquency board + case:** row action + case header *SMS* — same sheet with the
  thread context when one exists (reply vs compose resolved server-side by the
  existing find-or-create threading). The case timeline shows it via the standard
  message↔interaction path; no special casing.
- **Recipes doc.** `docs/automation-conditions.md` appendix gains two entries the
  pieces now support: *missed-call follow-up SMS* (trigger: call message created,
  conditions: direction inbound + outcome missed → send_sms — works today via the
  general editor; the Aircall events are S08-surface objects? **verify**: if call
  messages don't flow through `HasAutomationTriggers`, note the one-line addition
  as a fast-follow rather than silently expanding scope) and *voicemail
  acknowledgement*. Recipes are documentation + harness fixtures, not product UI.
- **Docs.** `06-communications.md`: add S11 inbox + S12 call surfaces to the shipped
  reality; `01-stack.md` page map: Inbox is real, calls are live. The standing rule:
  stale docs mislead every Cursor session.

## Acceptance criteria

- [ ] Contact and delinquency SMS entry points send through the existing pipeline;
      suppression/identity/segment behaviours identical to the inbox composer
      (component reuse asserted — no forked composer).
- [ ] Case-context SMS lands on the case timeline like any message.
- [ ] The missed-call recipe either works via the general editor (harness fixture
      committed) or the trigger-surface gap is documented as fast-follow — no silent
      scope growth.
- [ ] Docs updated; `en/es/fr` complete; es reviewed for the call+SMS vocabulary.

## Tests required

`SmsEntryTest::reuse_no_fork` (component identity + behaviour parity),
`SmsEntryTest::case_timeline_landing`, the recipe harness fixture (or the documented
gap), panel manual script for the three placements.
