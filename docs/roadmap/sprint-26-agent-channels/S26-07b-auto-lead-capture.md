# S26-07b — Auto-lead capture

**Depends on:** S26-07
**Blocks:** S26-09 (invariant 40 wording)
**Touches:** `unit-hq-api`

## Problem

S26-07's audience gate skips unmatched senders, including when the binding
is `audience = all`. That binding is supposed to auto-create a lead for
non-spam-shaped unknown senders instead of parking them in triage. Doing
it in S26-07 would have mixed the first live-channel send path with a
contact-creation amendment to invariant 40.

## What to build

Only when `config('communications.auto_lead_capture')` is true (new,
default `false`). Replaces the triage park for **non-spam-shaped** unknown
senders when the resolved binding has `audience = all`:

- Create `Contact` with the inbound channel as primary
  (`contacts.origin = inbound_auto`). Migration adds `origin` enum column
  `manual|inbound_auto|walk_in|import|ai_agent`.
- Create a lead `Deal` (`source = inbound_{channel}`).
- Write the `comms_triage` row **resolved** with `how = auto`.
- Tier-2 `contact.auto_created`.

Spam-shaped stays in triage: auto-generated headers, bounces, STOP
keyword, `noreply@` / `mailer-daemon` / role addresses, active
suppression, and `communications.spam_patterns`. This amends invariant 40
(S26-09 wording: *never untraceably*).

S26-07's unmatched-sender skip (`audience`) becomes: unmatched +
`audience != all` → skip `audience`; unmatched + `audience = all` +
flag off → skip `audience`; unmatched + `audience = all` + flag on +
spam-shaped → leave in triage; unmatched + `audience = all` + flag on +
non-spam → capture then continue the listener.

## Acceptance criteria

- [ ] `auto_lead_capture` on + unknown SMS sender + `audience = all`
      binding → contact with `origin = inbound_auto`, lead deal, resolved
      triage row, activity; listener continues into a turn.
- [ ] `noreply@` sender still parks in triage.
- [ ] Flag off → unmatched sender skipped `audience` (S26-07 behaviour).
- [ ] STOP / bounce / role-address / active suppression / matching
      `communications.spam_patterns` stay in triage.

## Out of scope

- Listener / send path (S26-07).
- Webchat (S26-07c).
- Invariant 40 wording in `09` (S26-09).
