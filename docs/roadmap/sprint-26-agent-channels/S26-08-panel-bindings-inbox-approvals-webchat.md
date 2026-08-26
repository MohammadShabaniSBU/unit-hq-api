# S26-08 — Panel: bindings settings, Inbox agent drafts and hand-back, public chat page

**Depends on:** S26-06, S26-07 (bindings settings, Inbox card, hand-back);
S26-07c for `/chat/:token` only
**Blocks:** nothing
**Touches:** `unit-hq-panel`

## Problem

S26-06/07 give the API a binding table, agent-drafted replies as pending
actions, and a human-ownership rule. None of it is operable until the
operator can configure bindings, approve or edit a draft from the thread
they are already looking at, and hand a thread back to the agent.

## What to build

### Settings → AI agents → Channels (`/settings/ai-agents/channels`)

- Table of bindings: agent, channel, site (or "All sites"), mode, audience,
  outside-hours behaviour, updated by/at. Archived hidden by default,
  `?status=all` toggle.
- Create/edit slideover. Site picker respects the employee's grants
  (company-wide sees all + "All sites"). Mode/audience selects with helper
  text per option (i18n, en/es/fr).
- Empty state copy makes the default explicit: *"No agent answers this
  channel until a binding exists."*
- `useAgentBindings` / `useAgentBindingList` composables; types in
  `app/types/agents.ts`. Permission gate `ai_agent_binding.manage` from the
  permissions mirror.

### Inbox

- **Agent draft card** on a thread with a pending `channel.send` action:
  the draft body (editable textarea prefilled), channel meta from
  `ChannelGuard` (segments for SMS, window countdown for WhatsApp — reuse
  the existing compose-context fields, do not re-implement), buttons
  *Send*, *Edit & send*, *Discard*. *Send* → `POST
  /api/agent-pending-actions/{id}/approve` (no body override). *Edit &
  send* → reject with `resolution = edited`, then a normal inbox reply
  with `agent_pending_action_id` (S26-07). Discard → `/reject` with
  `resolution = discarded`. A superseded or expired action renders a
  muted note.
- **Agent badge** on thread rows and in the conversation header: "AI
  replied" / "AI draft pending" / "Handed off — {reason}" with the handoff
  summary from `agent_handoffs.detail` in a popover. Reason labels via i18n
  keys from `HandoffReason`.
- **Hand back to agent** button on threads whose `agent_conversation.state`
  is `awaiting_human` / `handed_off` or that are human-owned by a manual
  reply: `POST /api/inbox/threads/{id}/agent/resume` (S26-07 adds it;
  sets `state = active`, clears human ownership, does **not** trigger a
  turn — the next inbound does). Only visible when a live binding exists
  for the thread's channel/site.
- Badge polling (`GET /api/inbox/badge`) gains `agent_drafts` and
  `agent_handoffs`; nav badge sums them with unread as today; document
  title/favicon logic unchanged.
- Trace access: on threads with an agent conversation, a "View trace" link
  opens the existing trace pane component from `/demo/chat` in a slideover
  (read-only). Requires `ai_agent.use`.

### Public chat page `/chat/:token`

Minimal, unauthenticated, no Nuxt UI dependency on auth state: message
list, input, polling `GET …/messages?after=`. Visibly labelled as automated
(the greeting already discloses; the header repeats it). Site branding from
the session's site name only. No offer tokens, no prices rendered other
than as text the agent sent.

## Acceptance criteria

- [ ] Bindings page lists demo rows; creating a duplicate `(channel, site)`
      shows the 422 message inline; archive removes from default list.
- [ ] Inbox: a seeded pending agent draft renders the card; *Send* produces
      an outbound message in the thread within one poll cycle; *Edit & send*
      sends the edited body; *Discard* hides the card.
- [ ] Handed-off thread shows the reason + summary popover; *Hand back*
      hides the card and the next simulated inbound gets an agent turn.
- [ ] `/chat/{token}` round-trips a message against a local API with the
      sales webchat binding.
- [ ] `bun run lint` + `bun run typecheck` clean; all new strings in
      `en.json`, `es.json`, `fr.json`; `Array<T>` throughout.

## Out of scope

- Editing bindings' per-tool write policy from the same page (stays on
  `/settings/ai-agents`).
- Chat widget embed script for third-party sites (page only).
