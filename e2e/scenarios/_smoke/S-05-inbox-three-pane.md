---
id: S-05
title: Inbox shows Pilar Santos's open WhatsApp window and can discard a triage stranger
domain: _smoke
route: /inbox
persona: ops
mutates: true
priority: smoke
requires_db: any
depends_on: []
anchors: [pilar]
invariants: []
---

## Preconditions

Demo world seeded. Reset first — step 8 writes a triage resolution. Pilar Santos (MAD-01) has an open WhatsApp session window. Front-desk misc left unmatched strangers on the **Triage** tab (`fixtures.md`).

## Steps

1. Log in as `ops`. Go to `/inbox`. Heading **Inbox**. The layout is thread list | conversation | context.
2. In the thread list, use the channel chips (**All**, **Email**, **SMS**, **WhatsApp**, **Calls**) and switch to **WhatsApp**.
3. Search threads for **Pilar Santos**. Open her thread.
4. Read the conversation. Confirm the WhatsApp dance: an outbound template, her inbound asking for a payment link, a session reply, and a late inbound that keeps the window open.
5. Switch the channel filter back to **All**. Toggle **Unread only** on, then off, to confirm the list updates.
6. Switch the list mode from **Threads** to **Triage**.
7. Open a stranger row. Recognised addresses (do not invent others): `stranger.one@unknown.example`, `stranger.two@unknown.example`, SMS `+34999888777`.
8. Click **Discard**. Confirm the discard dialog (reason can be `e2e-smoke`). Submit.

## Expected

- Pilar's WhatsApp thread is findable and shows the Spanish lines from the journey (template about her contract; inbound `¿pueden enviarme el enlace de pago?`).
- An open-window / in-session affordance is visible on that thread (the window is scripted to be open at seed-end).
- Triage lists at least one unmatched stranger. After discard, that row leaves the triage list and a **discard** success toast appears.
- The three-pane layout stays intact across filter changes.

## Known traps

- Pilar is a contact, so she lives under **Threads**, not **Triage**.
- Unread-cast names like Ana Unread / Bruno Unread are front-desk texture, not the stranger set. Do not discard those.
- Attaching or create-and-attach also mutates. This scenario uses **Discard** only.

## On failure

Screenshot Pilar's conversation (if the thread is wrong) or the triage pane (if discard failed). Do not create a contact for the stranger to "make the step work." Do not fix the bug — report it in `e2e/runs/<date>/bugs.md`.
