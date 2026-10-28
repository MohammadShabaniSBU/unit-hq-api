# Sprint 11 — Threads & Inbox

## Goal

One unified inbox: every conversation across email, SMS, and calls in a three-pane
surface — thread list, conversation, tenant context — with assignment, read state,
channel-appropriate reply, and the quick actions that turn a message into money
("request payment") or a tenancy ("send offer"). This is the mockup from the first
planning session, built as **pure UI + read/write endpoints over S10's tables**.

**Standing rule: zero migrations.** S10 promised the schema complete (assignment +
unread columns included). A migration appearing in this sprint is an S10 defect to fix
there, not new schema to add here. (Exception: none. WhatsApp columns already exist as
enum room.)

## Prerequisites to close in kickoff (carried from S09/S10 reviews)

1. `LeadKindTest` — the context panel links enrolments; test the exits first.
2. Playbook builder page location confirmed (or S09-04 completed).
3. Comms enforcement-matrix + adapter-fixture tests located/confirmed.

## Scope decisions (v1, recorded)

- **Read state is thread-level and shared** (the S10 `unread_count`), not per-employee.
  Per-employee read receipts → `10-open-decisions.md`.
- **Realtime is polling**: `updated_after` deltas every 20s + on window focus. WebSockets
  (Reverb) recorded as the upgrade path; the polling contract is designed so swapping
  transport changes no payload shapes.
- **Calls are read-only threads** (S10's Aircall messages); the composer shows a
  disabled "Call back — coming with phone integration" affordance (S12).
- **Search v1** = contact name/address + thread subject. Body full-text → deferred,
  recorded.
- **Manual replies are `transactional`** class (an operator answering a tenant is
  service, not marketing).

## Exit criteria

- [ ] The mockup, live: channel tabs, thread list with previews/unread/assignment,
      conversation with delivery-state chips, context panel with balance/unit/deal and
      working quick actions.
- [x] Reply on email (with attachments + template insertion) and SMS (char-counted)
      round-trips through the real senders; an inbound reply appears via polling
      without refresh.
- [x] Mine / Unassigned / All + channel + unread filters compose; nav badge counts live.
- [x] Triage tab resolves unmatched messages through all three S10 resolutions;
      move-thread has UI.
- [ ] Thread list at 500 seeded threads: bounded queries, <200ms aggregate endpoint
      (the standing performance posture).

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Inbox API surface](./00-inbox-api.md) | 1 day |
| 01 | [Reply & compose pipeline](./01-reply-and-compose.md) | 1 day |
| 02 | [Three-pane Inbox UI](./02-inbox-ui.md) | 1.5 days |
| 03 | [Context panel & quick actions](./03-context-panel.md) | 1 day |
| 04 | [Triage, read-state polish & badge](./04-triage-and-polish.md) | 1 day |

## Risks

**The aggregate endpoint is the whole page's performance.** Thread list = threads +
last-message preview + contact + assignment + unread in **one** query shape (lateral
join / subquery for the preview — never N+1 across 25 rows; the S01 matrix lesson,
fourth appearance). Cursor-paginate by `last_message_at` — offset pagination on a
moving inbox skips/doubles rows under new arrivals.

**Mark-read races the poller.** Opening a thread while a message arrives must not lose
the increment: mark-read sets `unread_count = 0` conditionally against the value the
client saw? No — simpler and correct: mark-read zeroes; a *subsequent* inbound
increments; the poller re-delivers the thread and the UI re-shows unread. Accept the
benign race; never decrement arithmetically.

**Composer identity must be honest.** From-address/number = the site's sender identity
resolved for the thread's contract-site context (fall back org account). If none
resolves, the composer disables with "configure a sender identity" — a reply silently
sent from the wrong site's identity is a trust incident with tenants.
