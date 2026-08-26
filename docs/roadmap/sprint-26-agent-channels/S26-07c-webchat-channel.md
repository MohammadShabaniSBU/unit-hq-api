# S26-07c — Webchat channel

**Depends on:** S26-07
**Blocks:** S26-08 (`/chat/:token` page only)
**Touches:** `unit-hq-api`

## Problem

`AgentChannel::Webchat` is a profile only. Comms `Channel` has no `webchat`
case, so the S26-07 listener cannot answer a widget: there is no inbound
`messages` row and no `InboundMessageReceived`. The demo `/demo/chat` page
talks to `AgentConversationController` directly and stays unchanged.

## What to build

Add `webchat` to the comms `Channel` enum with a first-party adapter (no
provider account):

- `chat_sessions` table (`token` crypto-random, `site_id` nullable,
  `contact_id` nullable, `message_thread_id`, `visitor_meta`,
  `last_seen_at`, `closed_at`).
- Public routes (allowlisted in `routes/api.php` with the token named as
  authenticator, invariant 42; rate-limited per token and IP):
  - `POST /api/chat/sessions`
  - `POST /api/chat/sessions/{token}/messages`
  - `GET /api/chat/sessions/{token}/messages?after=`
- Inbound writes a `messages` row (`direction = inbound`) and fires
  `InboundMessageReceived` like any channel; outbound is a `messages`
  row the widget polls.
- Session open with no message triggers the sales greeting turn (S26-04
  disclosure) — the session-open is the inbound event; the agent is still
  never proactive on provider channels.

Exhaustive `match` sites on comms `Channel` (`Threading`,
`ContactChannelMatcher`, `InboundReceiptApplier`, `TriageResolver`,
`ProviderRegistry`, `InboundSiteContext`, inbox validation) need a
`webchat` case. The `message_threads` partial unique index for
`(contact_id, channel, channel_key)` currently covers `sms|call|whatsapp`;
extend it for `webchat`.

The demo `/demo/chat` page is unchanged; a minimal `/chat/{token}` public
page is S26-08.

## Acceptance criteria

- [ ] Webchat: open session → greeting with disclosure; message → reply
      polled; no provider account involved.
- [ ] `RouteAuthCoverageTest` allowlist entries for the three chat routes.
- [ ] Exhaustive `Channel` matches compile / tests pass with the new case.

## Out of scope

- Public chat page (`/chat/:token`) — S26-08.
- Demo `/demo/chat` changes.
- Chat widget embed script for third-party sites.
