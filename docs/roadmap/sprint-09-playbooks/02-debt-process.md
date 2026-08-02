# S09-02 — Debt process semantics

## Context

The flagship: enrolment on delinquency case open, exit on cure, steps that chase money
across channels — the flow described in the very first product brief, now expressible as
configuration on proven machinery.

## Scope

**In:** `DebtProcess` kind implementation, enrolment filters, the cure guard, pairing
sugar (`record_notice` on send steps), delinquency-ladder coexistence rules, seeded
default playbook, case-timeline integration.
**Out:** replacing the S07 ladder (they coexist — see below), multi-playbook priority
(one active debt playbook per site-filter set in v1; overlap validation rejects
ambiguity, richer routing recorded in `10-open-decisions.md`).

## Behaviour

**Kind definition.**
- `trigger()`: `object_created` on `Delinquency` (the S08-02 surface).
- `enrolment_filters`: `{ site_ids?: [..], policy_ids?: [..], min_days_overdue?: n }`
  compiled into trigger conditions (whitelisted fields only — S08-02's rule). Empty =
  all cases.
- `guard()`: live-source `cured_on is_not_empty` on the subject case — the S08 exit
  recipe verbatim. Cure through **any** path (payment via the allocator hook, write-off,
  vacate) exits the enrolment; that chain is already built, this just rides it.
- `allowedActions()`: all four. `subjectDescriptor()`: delinquency.

**Ladder coexistence.** The S07 policy ladder owns *consequences* (fees, overlock);
the playbook owns *communications and tasks*. Same case, two actors, one timeline. Guard
this boundary in kind validation: debt playbooks may not… actually cannot assess fees or
overlock (no such actions exist for them) — the boundary is structural, state it in the
page copy instead ("fees and overlock are configured in Late fees & delinquency"). The
case timeline interleaves both actors' steps chronologically; `trigger` values
(`ladder` / `playbook` / `manual` / `cure`) already distinguish them after 01.

**Pairing sugar.** A debt step with `action: send_email|send_sms` accepts
`"record_notice": "overdue"` in params — compiler expands it to the send node followed by
a `record_notice` node whose row records `sent_at` + channel from the send's outcome
(skipped send → unsent notice, still recorded: the *attempt* to notify is itself the
audit fact; note this in the file, it will be questioned).

**Seeded default** (inactive — operator reviews and activates): D0 email
"payment reminder" (template seeded, tokens: name, amount, pay-link *placeholder* —
payment-request generation inside playbooks is a **noted gap**: the send can reference
the balance but auto-creating a payment link per enrolment is an S10-era action; record
in `10-open-decisions.md`), D2 SMS nudge, D4 email + `record_notice: overdue`, D7 urgent
task "call the tenant".

**WhatsApp session subtlety (S13-04).** A debt step may send an approved *utility*
template via `send_whatsapp_template`, but that outbound does **not** open Meta's 24h
customer-service window — only the tenant's inbound reply does. The enrolment does not
react to replies (standing reply-exit deferral). When the tenant answers, the operator
continues free-form in the inbox composer while the window is open.

## Acceptance criteria

- [ ] Case open (engine-written, queue context) enrols per filters; `min_days_overdue`
      gates via condition; single-enrolment holds when a cured contract re-delinquens
      into a **new** case (new case = new subject ⇒ new enrolment — assert this
      explicitly, it's the intended semantics).
- [ ] Cure by payment, write-off, and vacate each exit mid-wait via the guard.
- [ ] Pairing sugar expands correctly; skipped-send still records the unsent notice.
- [ ] Case timeline interleaves ladder + playbook + manual entries chronologically with
      distinguishable badges.
- [ ] Seeded default compiles, harness-runs the full sequence, and matches fixture 10's
      skeleton.
- [ ] Overlapping active debt playbooks (same site) rejected at activation.

## Tests required

| Test | Asserts |
|---|---|
| `DebtKindTest::enrolment_filters_and_new_case_reenrolment` | Semantics pinned |
| `DebtKindTest::all_three_cure_paths_exit_midwait` | The headline behaviour |
| `DebtKindTest::pairing_sugar_and_skipped_send_notice` | Audit facts |
| `DebtKindTest::timeline_interleave` | Two actors, one story |
| `DebtKindTest::activation_overlap_rejected` | v1 routing |
