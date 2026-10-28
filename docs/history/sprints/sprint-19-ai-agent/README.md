# Sprint AR — Agent: background execution, broadcast transport, usage metering

## Goal

Move the CRM copilot from a request-scoped SSE stream to a **queued agent whose stream is
broadcast over Reverb**, so that an agent turn owns its own lifecycle. Once a turn survives
the request, three things become possible that are impossible today: human tool approvals
that sit pending while an operator decides, agent runs triggered by automations with no
request at all, and reliable token accounting that does not depend on the browser staying
connected.

## Why now

The current entry point is:

```php
return (new CrmCopilotAgent($messages))
    ->stream($lastUserMessage)
    ->usingVercelDataProtocol();
```

Three problems, in dependency order:

1. **Conversation history is constructor-injected, so approvals cannot work.** If a
   `messages` method is present it takes precedence over `RemembersConversations` and history
   is never loaded from the database. Tool approval requires a `Conversational` agent whose
   history is persisted, because the paused call is resumed from that history. Every write
   tool planned for the agent expansion depends on this.
2. **The turn dies with the request.** A closed tab, a Traefik read timeout, or a tool chain
   that outruns `#[Timeout]` kills the run mid-flight. No terminal event fires, so usage is
   never recorded and the conversation is left in an indeterminate state.
3. **Usage is captured at the call site**, which means every future feature that prompts an
   agent has to remember to record it.

## Scope of the sprint

**In:** conversation persistence migration, queued + broadcast transport, Echo consumption in
the panel, channel authorization, usage metering with reserve/settle, worker configuration.

**Out:** the tool expansion itself (separate sprint), RBAC/`employee_site` (prerequisite,
separate sprint), provider credential settings UI, model bindings.

## Prerequisite

Task 01 assumes `laravel/ai` **≥ 0.10.0**. Approval state is stored in a nullable
`approval_state` column on the conversation messages table, added by that release's
migration. Confirm the installed version before starting.

## Task order

Strictly sequential.

| # | Task | Est. |
|---|---|---|
| 01 | [Conversation persistence and participant model](./01-conversation-persistence.md) | 1 day |
| 02 | [Queued execution and broadcast transport](./02-queued-broadcast-transport.md) | 1.5 days |
| 03 | [Usage metering](./03-usage-metering.md) | 1 day |

## Exit criteria

- [x] `CrmCopilotAgent` uses `RemembersConversations`; no `messages` method exists on the
      class; conversations belong to an `Employee` participant.
- [x] Sending a message returns `202` immediately; the turn runs in a queue worker.
- [x] The panel renders streamed text, tool calls and completion from Echo, with no SSE
      endpoint involved.
- [x] Closing the browser tab mid-turn does not abort the run; the conversation shows the
      complete assistant message on reload.
- [x] Every completed turn has exactly one settled `ai_usage_events` row attributed to the
      correct employee.
- [x] A turn that fails or times out leaves a terminal (`failed` / `orphaned`) usage row, not
      a missing one.
- [ ] An employee cannot subscribe to another employee's conversation channel.
- [ ] The existing Vercel-protocol features elsewhere in the panel are untouched.

## Risks

**The frontend transport is the real cost.** `usingVercelDataProtocol()` shapes an SSE body;
`broadcastOnQueue` pushes SDK event objects onto a channel. They are different wires and do
not compose. Task 02 deliberately does **not** attempt to write a custom Vercel
`ChatTransport` over Echo — it builds a `useCopilotStream` composable that consumes Echo
directly. This keeps the copilot idiomatic to the existing panel conventions (Pinia,
composables, `useApi()`) and leaves the other Vercel-protocol features alone. Revisit only if
the copilot UI later needs to share components with those features.

**Broadcast payload limits.** Most broadcasting platforms cap messages near 10KB. Planned
tools like `get_contact_overview` return 4–6k tokens of JSON. Task 02 excludes tool events
from broadcasting; the panel loads tool data from the conversation messages table after the
stream completes.

**Worker timeout ladder.** If `queue:work --timeout` is below the agent's `#[Timeout]`, the
worker kills the process mid-turn and the terminal event never fires — reintroducing exactly
the bug this sprint exists to remove. Task 02 specifies the ladder explicitly.

**No live data.** As with S01, there is no production dataset. Existing dev conversations are
disposable; task 01 does not backfill.
