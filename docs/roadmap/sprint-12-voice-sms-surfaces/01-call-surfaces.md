# S12-01 — Call surfaces & incoming banner

## Context

Where the dial button lives, and the moment of delight: the phone rings and the screen
already knows who it is. Every surface consumes 00's availability truth and dial
endpoint — this task is placement, context wiring, and the banner.

## Scope

**In:** call-back live in inbox call threads, call actions on contact page /
delinquency board / task detail / context panel, the incoming/active-call banner,
"unknown caller" resolution path. **Out:** any dial-pad UI (numbers come from records),
transfer/conference anything (Aircall's app owns in-call), banner realtime beyond the
poller (the README honesty rule).

## Panel surface

**Placement (all gated by 00's availability; disabled states name the reason —
"Link your Aircall user in Settings" vs "Your Aircall device is offline"):**

- **Inbox call thread:** S11's disabled affordance goes live — "Call back" dials the
  thread's `channel_key`, context `{thread}`.
- **Context panel (S11-03):** phone row gains a call glyph; context = thread.
- **Contact page:** header quick action + per-phone-channel buttons; context
  `{contact}`.
- **Delinquency board + case:** the S07 board rows and the case header gain Call —
  context `{delinquency}`; after 00's correlation, the call lands **on the case
  timeline** as a step-adjacent entry (render call messages with `context =
  delinquency` in the timeline interleave — the collections story completes: fee →
  overlock → *called them* → paid).
- **Task detail:** tasks whose related contact has a phone get Call; completing the
  loop for S07's "urgent: call the tenant" and the playbooks' `create_task` steps.

**Incoming/active-call banner.** A slim top-of-app banner (not a toast — it persists
for the call's duration) driven by the poller's badge payload gaining
`active_calls: [{direction, phase: ringing|ongoing, contact: {...}|null, number,
thread_id, started_at}]` (computed from call messages in non-terminal phases — S10
stores the lifecycle events; no new state). Known contact: name + "Open thread" +
context chips (overdue badge when delinquent — the operator answers *knowing*).
Unknown number: "Unknown caller +34…" + "Resolve" opening the triage-style
attach/create flow against the call message. Phase honesty per the README: the banner
renders whatever phase the last webhook reported; post-pickup appearance reads
"On a call with…" — correct, if late.

**Voicemail rows** in inbox threads get a distinct icon + "Voicemail" label and keep
unread until opened (S10 set the unread; this styles it) with playback via 02.

i18n `calls.*`; es: Call back → *Devolver llamada*, On a call → *En llamada con…*,
Unknown caller → *Llamada de número desconocido*.

## Acceptance criteria

- [ ] Every placement dials with its context; the delinquency-context call appears on
      the case timeline interleaved chronologically.
- [ ] Disabled states show the correct reason per the availability contract.
- [ ] Banner: known-contact ringing (webhook fixture) renders within one poll cycle
      with context chips; unknown number offers Resolve and the attach flow lands the
      call message on the created/chosen contact's thread.
- [ ] Banner clears on `call.ended` within one cycle; two simultaneous calls stack.
- [ ] Task-detail call on a seeded S07 "call the tenant" task round-trips: dial →
      correlate → the case timeline shows the call → operator completes the task.

## Tests required

API: `ActiveCallsTest::badge_payload_phases_and_matching`,
`CallContextTest::delinquency_timeline_interleave`. Panel manual script: placements
sweep with both disabled reasons (1), banner known/unknown/stacked/cleared (2), the
task round-trip (3), voicemail styling (4).
