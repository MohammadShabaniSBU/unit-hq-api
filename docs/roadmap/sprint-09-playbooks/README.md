# Sprint 09 — Playbooks: Debt Process & Lead Chase

## Goal

The two purpose-built automation surfaces from the original product brief: **Debt process**
("day 1 email, day 2 SMS, day 3 urgent task — all cancelled the moment they pay") and
**Lead chase** (maximise lead→tenant conversion with a timed follow-up sequence). Both are
**linear step sequences with a fixed exit condition**, compiled onto the hardened engine —
no visible triggers, no branch nodes, no graph canvas. The general editor stays for power
users; these pages are for operators.

## Architecture (decided in early planning, now buildable)

A playbook is **not** a simpler skin over the graph editor. It is a linear model
(`playbooks` + ordered `playbook_steps`) that **compiles** to an automation graph:
trigger → [wait → action]×N, with the exit condition set as the run **guard** (S08-00) at
enrolment. The cancel-on-payment behaviour is the guard's three evaluation points doing
their job — during waits included. S08's fixtures 10–12 are the compiler's target shapes;
they were written as this sprint's spec.

Enrolment = an automation run. The playbook UI renames the vocabulary (enrolment, step,
exit) but reads/writes engine tables — one execution truth, two presentations.

## Verified inputs

`EmailSender` + `SmsSender` (Brevo/Postmark/Twilio adapters, company-scoped accounts),
`SendEmailHandler`, `EmailTemplate` model, run guards + waiting + harness (S08), the
delinquency trigger surface + exit recipes (S08-02), `contract_notices` + case timeline
(S07). Missing and added here: an SMS action handler, a record-notice handler, and
single-active-run-per-subject enrolment semantics.

## Exit criteria

- [x] An operator with no automation knowledge builds "day 1 email, day 3 SMS, day 5
      urgent task" in the Debt process page in under two minutes, activates it, and a
      seeded delinquent contract enrols on the next case-open.
- [ ] Paying mid-sequence — during a wait — exits the enrolment within minutes; the
      case timeline and the enrolment view both tell the story.
- [ ] Lead chase enrols new deals, exits on stage advance or loss, and never
      double-enrols a subject.
- [x] Editing an active playbook affects future enrolments only, with existing ones
      finishing on the graph version they started (or being bulk-exited, operator's
      explicit choice).
- [ ] Every new handler has a harness fixture (`HandlerCoverageTest` stays green).

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Playbook model & compiler](./00-playbook-model-and-compiler.md) | 1.5 days |
| 01 | [Actions: SMS, notice, templated email](./01-playbook-actions.md) | 1 day |
| 02 | [Debt process semantics](./02-debt-process.md) | 1 day |
| 03 | [Lead chase semantics](./03-lead-chase.md) | 0.5 day |
| 04 | [Panel: the two builders](./04-panel-playbook-builders.md) | 1.5 days |

## Risks

**Version drift between playbook and compiled graph.** The linear model is authoritative;
the graph is build output. Nobody edits compiled graphs in the general editor —
compiled automations are flagged and read-only there (view allowed, edit routed to the
playbook page). Otherwise the compiler and reality diverge on first manual tweak.

**Sends without consent plumbing.** Real consent enforcement is the comms phase (S10+).
Interim floor, non-negotiable: sends go only to the contact's primary channel of the
right type; a missing channel records the step as skipped-with-reason, never errors the
run; and every send writes the `Interaction` + `OfferDelivery`-style provenance the
existing senders already produce. Do not build a consent model here — and do not send to
arbitrary addresses either.

**Quiet-hours.** Day-offset waits landing at 3 a.m. site-time send at 3 a.m. v1 rule:
waits resolve to the site's configured send window start (default 09:00, org setting) —
a small `wait_until_window` compile detail, not a scheduling engine. Record fancier
windowing (weekends, per-step times) in `10-open-decisions.md`.
