---
id: INB-02
title: Bea Torres shows suppressed email and an SMS fallback thread
domain: inbox
route: /inbox
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [bea]
invariants: []
---

## Preconditions

Compact demo world. Bea Torres has a hard-bounced / suppressed email and an SMS fallback ("We could not reach you by email…"). Read only.

## Steps

1. Log in as `ops`. Go to `/inbox`. Heading **Inbox**.
2. Switch the channel chip to **Email**. Search for **Bea Torres**. Open the thread if present.
3. Confirm a bounce / suppressed affordance on the email channel (badge, banner, or failed delivery).
4. Switch the channel chip to **SMS**. Open Bea's SMS thread.
5. Confirm the fallback copy about not reaching her by email. Do not send.

## Expected

- Email side shows suppression / bounce, or the contact/context pane marks email suppressed.
- SMS fallback thread is present with the seeded fallback wording.
- Do not send on either channel.

## Known traps

- Type **Bea Torres**, not `BeaTorres`.
- Do not "fix" suppression by sending a new email.

## On failure

Screenshot the email suppression affordance and the SMS fallback thread. Do not send. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
