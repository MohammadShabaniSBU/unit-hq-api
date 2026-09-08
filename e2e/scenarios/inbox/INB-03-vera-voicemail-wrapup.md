---
id: INB-03
title: Calls list shows Vera Voicemail's wrap-up
domain: inbox
route: /inbox
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [voicemail]
invariants: []
---

## Preconditions

Compact demo world. Vera Voicemail (handle `voicemail`) has an inbound call wrap-up: left voicemail about unit availability. Read only.

## Steps

1. Log in as `ops`. Go to `/inbox`. Heading **Inbox**.
2. Switch the channel chip to **Calls**.
3. Search or scan for **Vera Voicemail**. Open the thread or wrap-up.
4. Confirm a voicemail wrap-up is on file (outcome / note about unit availability).
5. Do not place a call, send a follow-up, or discard the row.

## Expected

- Vera's call / wrap-up is findable under **Calls**.
- The wrap-up mentions voicemail (and unit availability if the note is shown).
- Do not use the composer.

## Known traps

- Display name is **Vera Voicemail**. Do not confuse with Walter Wrong (wrong-number texture).
- Unknown missed call `+34999000111` is triage, not this contact.

## On failure

Screenshot the Calls list and the wrap-up detail. Do not send. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
