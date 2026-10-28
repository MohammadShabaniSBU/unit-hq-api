# S11-04 — Triage, read-state polish & badge

## Context

The unglamorous last mile: the unmatched-message triage gets its UI (S10 built the
resolver; operators need the queue), move-thread gets its picker, the nav badge goes
live, and the read-state/notification details that make an inbox trustworthy get
finished.

## Scope

**In:** triage tab + resolve flows, move-thread UI, nav badge wiring, mark-unread,
per-thread mute? no — **not** in scope (noted), notification niceties (title count
already in 02; favicon dot), i18n/es sweep, docs update. **Out:** browser push
notifications (needs service-worker + permission UX — `10-open-decisions.md`),
per-employee read state (standing deferral).

## Panel surface

**Triage tab** (in the thread-list header, badge-counted): rows show parsed sender,
channel, received time, message preview. Opening one shows the full (sanitized)
message + the three resolutions as explicit buttons:

1. **Attach to existing contact** — contact search (the standard picker); on confirm
   the S10 resolver creates the real message/thread and the row leaves the queue;
   navigate to the resulting thread.
2. **Create contact & attach** — minimal modal (name, the channel value prefilled as
   the appropriate channel row); same continuation.
3. **Discard** — confirm with reason optional; Tier-2 comms activity (`triage.discarded`
   — spam metrics later want this).

Resolution actions audit (`triage.resolved`, properties: how). The queue empty-state
says what triage *is* — operators will meet it before they read docs.

**Move-thread UI.** Conversation-header action → picker listing the contact's other
threads of the channel (+ "new thread"); confirm shows the S10 endpoint's audit note.
Mis-threaded email is when this earns its keep; the action is discoverable but not
prominent.

**Badge + read polish.** Sidebar Inbox badge = `unread_threads` (+ triage dot when
`triage_count > 0`), fed by the 00 endpoint on the same poll. Mark-unread action
(sets `unread_count = 1` — the honest hack; per-message read is out of scope and the
thread-level model owns it). Favicon dot when unread > 0.

## Docs

Update `06-communications.md` to describe the shipped reality (message store as
canonical, Interaction as index, triage, suppression) — it still documents the
pre-S10 world and Cursor reads it. Add the Inbox to the panel page map in
`01-stack.md`.

## Acceptance criteria

- [ ] Seeded triage rows resolve through all three paths end-to-end; audits written;
      queue count updates live.
- [ ] Move-thread picker works; the moved message renders in its new home with the
      audit visible in thread actions history.
- [ ] Badge (+ triage dot, + favicon) accurate within one poll cycle across two open
      tabs.
- [ ] Mark-unread round-trips with the list and badge.
- [ ] `06-communications.md` + `01-stack.md` updated; `en/es/fr` complete; es reviewed.

## Tests required

| Test | Asserts |
|---|---|
| `TriageUiTest::three_resolutions_end_to_end` | API-driven, incl. audits |
| `MoveThreadTest::picker_flow_and_audit` | The S10 endpoint clothed |
| `BadgeTest::counts_within_poll_cycle` | The 00 contract consumed |
| Panel manual script | Two-tab badge sync, favicon, empty states |
