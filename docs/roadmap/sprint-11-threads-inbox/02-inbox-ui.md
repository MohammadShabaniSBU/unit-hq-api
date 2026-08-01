# S11-02 — Three-pane Inbox UI

## Context

The page itself: the pinned Inbox from every mockup since day one. Left: threads.
Centre: conversation + composer. Right: context (03). This task builds panes one and
two plus the polling loop; it is the sprint's largest UI lift and should feel like a
messaging product, not a CRUD table.

## Scope

**In:** layout + routing (`/inbox`), thread list with filters/search/infinite cursor,
conversation view with day separators + status chips + attachments + source badges,
composer mount (01's components), polling loop + focus refresh, keyboard basics
(j/k navigate, Enter open, r reply-focus), empty/loading states. **Out:** context
panel (03), triage tab (04), realtime transport upgrades.

## Panel surface

**Thread list (left, 360px).** Header: channel tabs (`All · Email · SMS · Calls` with
per-tab unread dots), filter segmented control (Mine / Unassigned / All), search field,
unread-only toggle. Rows: avatar-initials, name, preview line (direction glyph for
outbound), relative time, unread badge, assignment avatar chip, channel glyph,
suppression micro-icon when flagged. Active row highlighted; infinite scroll on the
cursor; new-arrivals via poller **prepend with a "N new" pill** rather than yanking
scroll position (the moving-inbox UX counterpart of 00's cursor rule).

**Conversation (centre).** Header: contact name → link, channel + counterpart address,
assignment select (inline, from 00), thread actions menu (mark unread, move-to-thread —
04 wires the picker). Body: ascending messages grouped by day; bubbles styled by
direction (the mockup's WhatsApp-like read); per-message: status chip progression
(sent→delivered→opened / bounced red with reason on hover), source badge for
non-manual (`Playbook · Debt process step 2` linking the run; `Offer #45` linking the
offer — S10's source_ref cashed in), attachments as download chips, sanitized HTML in
a constrained container (max-width, no external loads — the sanitizer guarantees, the
CSS contains). Call messages render as call cards (direction, duration, outcome,
recording link opening provider URL) with the disabled call-back affordance.

**Composer (bottom).** 01's components; collapses on call threads to the affordance;
Cmd/Ctrl-Enter sends; sending state on the message optimistically as `queued` then
reconciled by the next poll (**display** optimism only — the S06 discipline restated
for UI: no local state pretends delivery).

**Polling.** `useInboxSync`: 20s interval + focus + post-send immediate; merges
`updated_after` deltas into list and open thread; document-title unread count.

i18n `inbox.*`; es-first review pass required this sprint (the operator lives here):
Mine → *Míos*, Unassigned → *Sin asignar*, the status chips, the "N new" pill.

## Acceptance criteria

- [ ] The S10 seed renders: mixed channels, unread, assignments, playbook/offer badges,
      a call card, a suppressed thread.
- [ ] Filters/tabs/search compose and survive refresh (query-string state).
- [ ] Arrival mid-scroll: pill appears, scroll holds, click reveals.
- [ ] Reply appears optimistically, reconciles to sent/delivered chips via poll; an
      inbound (simulated webhook) appears within one poll cycle unrefreshed.
- [ ] Status chips + source badges link correctly (run log, offer).
- [ ] Keyboard triad works; `lint`+`typecheck`; `en/es/fr` complete with es reviewed.

## Tests required

Panel-heavy: manual script in PR (the eight scenarios above, numbered) + API-side
already covered. If the component-test setup ever lands, `ThreadListRow` and the
status-chip progression are the first candidates (noted, standing).
