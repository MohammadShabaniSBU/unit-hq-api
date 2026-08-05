# AR-01 — Conversation persistence and participant model

## Context

`CrmCopilotAgent` currently receives `$messages` through its constructor and returns them
from a `messages` method. That blocks everything downstream: when a `messages` method is
present it takes precedence over the `RemembersConversations` trait and conversation history
is never loaded from the database. Tool approval — the mechanism that gates every write tool
in the agent expansion — requires a `Conversational` agent whose history is persisted,
because the paused tool call is resumed from that stored history.

This task moves conversation storage to the SDK's own tables and makes `Employee` the
conversation participant.

## Scope

**In:**
- `RemembersConversations` on `CrmCopilotAgent`; removal of the constructor `$messages`
  parameter and the `messages` method
- `HasConversations` on `Employee`; morph map entry
- Conversation list / show / create endpoints backed by the SDK tables
- Ownership authorization on every conversation route
- Retirement of the existing message table

**Out:**
- Queueing and broadcasting (task 02)
- Usage metering (task 03)
- Tool changes of any kind

## Schema changes

The SDK's `vendor:publish` migration creates `agent_conversations` and
`agent_conversation_messages`. Verify both exist and that the `approval_state` column is
present on the messages table — it arrives with `laravel/ai` 0.10.0. If the package was
installed before 0.10.0, upgrade and run the new migration before anything else.

Additive migration of our own:

```sql
-- migration: add_site_scope_snapshot_to_agent_conversations
ALTER TABLE agent_conversations ADD COLUMN site_scope_snapshot JSONB NULL;
```

`site_scope_snapshot` records which site ids the participant could see when the conversation
began. It is **audit only** — permissions are re-checked on every resume and the snapshot is
never used as a query filter. Write it at conversation create so a later reader can
reconstruct why the agent saw what it saw.

The existing bespoke message table is dropped in a separate migration **after** the new path
is verified working. There is no live data; do not write a backfill.

## Implementation notes

**Agent class.** Target shape:

```php
namespace App\Ai\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\{Agent, Conversational, HasTools};
use Laravel\Ai\Promptable;

class CrmCopilotAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(public Employee $employee) {}

    public function instructions(): string { /* ... */ }

    public function tools(): iterable { /* ... */ }
}
```

Do **not** define a `messages` method. The constructor now carries the actor, not the
history — which is also the shape the tool expansion needs, since tools receive the actor by
constructor injection rather than reading the session.

**Participant.** `Employee` is not `User`. Add the `HasConversations` trait to `Employee` and
use `forParticipant($employee)` to start a conversation (`forUser` is an alias for it). The
participant's morph class and primary key are stored on the conversation, so register
`employee` in the morph map that is already declared explicitly per the API conventions —
without it, stored participant types couple to class names and a later namespace move breaks
history.

**Authorization — the trap.** `continue()` does **not** verify that the given participant
owns the conversation. Every route that touches a conversation must authorize first. Add a
`ConversationPolicy`:

```php
public function view(Employee $employee, Conversation $conversation): bool
{
    return $conversation->participant_type === 'employee'
        && $conversation->participant_id === $employee->id;
}
```

Call `Gate::authorize('view', $conversation)` on show, on message send, and on approval
decisions. Task 02 adds the same check to the broadcast channel.

**Starting vs continuing.** A new conversation is created by prompting with
`forParticipant($employee)`; `$response->conversationId` is returned to the panel and stored.
Subsequent turns use `continue($conversationId, as: $employee)`. Keep this distinction in the
controller, not in the panel — the panel should POST to the same endpoint either way and let
the API decide based on whether a conversation id was supplied.

**Conversation listing.** The `conversations` relationship from `HasConversations` is a
polymorphic relationship scoped to the model type and primary key, so
`$employee->conversations()->latest('updated_at')->paginate(20)` is the list query. Response
shape through `ApiResponsable` as usual.

## API surface

```
GET    /api/copilot/conversations                    paginated, current employee only
POST   /api/copilot/conversations                    { title? } → creates, returns id
GET    /api/copilot/conversations/{conversation}     messages, paginated, oldest first
DELETE /api/copilot/conversations/{conversation}     soft delete / archive
POST   /api/copilot/conversations/{conversation}/messages   { message }
```

The message endpoint stays synchronous in this task — it still returns the SSE stream. Task
02 changes it to return `202`. Splitting it this way keeps task 01 independently shippable
and testable.

## Panel surface

Minimal. The existing copilot UI keeps working against the same endpoint; only the
conversation list and the stored conversation id change. Add:

- Conversation list in the copilot drawer, ordered by `updated_at`
- New conversation action
- i18n keys under `copilot.conversations.*` in `en.json`, `es.json`, `fr.json`

## Invariants

- **Mono-tenant** — no `company_id` on the new columns; `site_scope_snapshot` is audit
  metadata and must never appear in a query filter, a global scope, or a middleware filter.
  Same rule as `legal_entity_id` in `architecture-payments-and-fiscal.md`.
- **Migrations are additive** — never edit the published SDK migration. Our columns go in our
  own migration file.
- **Panel:** all strings via i18n; `Array<T>` typing; HTTP via `useApi()`.

## Acceptance criteria

- [ ] `laravel/ai` is ≥ 0.10.0 and `agent_conversation_messages.approval_state` exists.
- [ ] `CrmCopilotAgent` has no `messages` method and no `$messages` constructor argument.
- [ ] `Employee` uses `HasConversations`; `employee` is registered in the morph map.
- [ ] A new conversation persists to `agent_conversations` with the employee as participant
      and a populated `site_scope_snapshot`.
- [ ] Continuing a conversation loads prior messages without the panel sending them.
- [ ] Employee B receives 403 on every conversation route for Employee A's conversation.
- [ ] The bespoke message table is dropped and no code references it.
- [ ] `php artisan test` green; `bun run lint` and `bun run typecheck` green.

## Tests required

| Test | Asserts |
|---|---|
| `CopilotConversationTest::starting_conversation_persists_participant` | Morph type `employee`, correct id |
| `CopilotConversationTest::continuing_loads_prior_messages` | History present without client-supplied messages |
| `CopilotConversationTest::other_employee_cannot_view_conversation` | 403 on show, send, decisions |
| `CopilotConversationTest::site_scope_snapshot_written_on_create` | Column populated, not used in filtering |
| `CopilotConversationTest::agent_has_no_messages_method` | Reflection guard against regression |
| `CopilotConversationTest::conversation_list_scoped_to_employee` | Pagination returns only own rows |
