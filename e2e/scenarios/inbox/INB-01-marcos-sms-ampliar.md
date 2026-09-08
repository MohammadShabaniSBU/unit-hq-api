---
id: INB-01
title: Marcos Vega's SMS thread contains the enlarge ask
domain: inbox
route: /inbox
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [marcus]
invariants: []
---

## Preconditions

Compact demo world. Marcos Vega has an SMS thread asking to enlarge (`ampliar`). Read only — do not use the composer.

## Steps

1. Log in as `ops`. Go to `/inbox`. Heading **Inbox**.
2. Switch the channel chip to **SMS**.
3. Search threads for **Marcos Vega**. Open the thread.
4. Read the conversation. Confirm the enlarge ask (`ampliar` or equivalent size-upgrade wording).
5. Do not type in the composer. Do not click Send, Reply, or Resend.

## Expected

- The SMS thread is findable.
- At least one message asks to enlarge / `ampliar`.
- The composer is not used.

## Known traps

- Type **Marcos Vega**. Pilar's WhatsApp thread is S-05, not this scenario.
- Seed-time fakes wrote this thread. Sending now would hit a live provider.

## On failure

Screenshot the SMS thread with the enlarge line visible. Do not send. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
