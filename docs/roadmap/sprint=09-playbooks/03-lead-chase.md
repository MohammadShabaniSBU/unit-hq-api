# S09-03 — Lead chase semantics

## Context

The conversion half: a new deal gets a timed follow-up sequence; advancing or losing the
deal exits it. Structurally the debt kind with different nouns — which is the payoff of
00's kind registry: this task is mostly configuration and its edge semantics.

## Scope

**In:** `LeadChase` kind, enrolment filters, exit guard, seeded default, the quiet-deal
variant recipe.
**Out:** reply-detection exit ("they answered the email") — requires inbound comms
(S10+); recorded as the known upgrade to this kind's guard, with the provenance ids from
01 as its future join keys. Multi-sequence nurture branching — that's the graph editor's
job, deliberately.

## Behaviour

**Kind definition.**
- `trigger()`: `object_created` on `Deal`.
- `enrolment_filters`: `{ site_ids?, stages?: [..], sources?: [..] }` — stages limits
  enrolment to deals *created in* those stages (typically the first).
- `guard()`: live on the deal — exits when `stage` moved beyond the enrolled stage set
  **or** deal is lost/won. Concretely: `stage not_in enrolment_stages OR status in
  (won, lost)` — compiled per-playbook from its filters (a guard referencing "the stage
  at enrolment" needs the enrolment-time value: store it in the run's trigger snapshot
  and reference via the snapshot-source rule… no — guards are live-source. Resolution:
  the *filter's* stage list is static config, so `stage not_in {configured stages}` is
  expressible without per-run state. A deal moving *backward* into a configured stage
  after exit does not re-enrol — `object_created` fires once. Pin both semantics in
  tests.)
- `allowedActions()`: send_email, send_sms, create_task (no notices — kind-restricted
  in 01). `subjectDescriptor()`: deal.

**Quiet-deal variant.** "Enrol deals untouched for N days" is the S08 fixture-12 shape:
a schedule trigger + conditions. v1 ships it as a **documented recipe** on the general
editor (the fixture is importable), not a playbook kind — a second kind for it doubles
UI for a niche flow; revisit if operators ask. Note in the page copy where to find it.

**Seeded default** (inactive): D0 email "thanks for your enquiry" (template seeded),
D1 task "call the lead", D3 SMS "still looking for space?", D7 email "offers this month".

## Acceptance criteria

- [ ] New deal in a configured stage enrols; other stages don't; sources filter works.
- [ ] Stage advance, win, and loss each exit mid-wait; backward-stage moves after exit
      do not re-enrol.
- [ ] Notices rejected at compile for this kind.
- [ ] Seeded default compiles and harness-runs; matches fixture 12's enrolment shape
      where applicable.
- [ ] Deal detail links an active enrolment (surface built in 04).

## Tests required

| Test | Asserts |
|---|---|
| `LeadKindTest::enrolment_filters` | Stage/source gating |
| `LeadKindTest::three_exits_midwait_no_reenrol_backward` | Guard + trigger semantics |
| `LeadKindTest::notice_action_rejected` | Kind restriction |
| `LeadKindTest::seeded_default_harness_run` | End to end |
