# AR-02 — Queued execution and broadcast transport

## Context

With history persisted (task 01), the turn can leave the request. This task moves execution
to a queue worker and streams the result over Reverb, so a turn survives a closed tab, a
proxy timeout, and an approval pause.

`usingVercelDataProtocol()` shapes an SSE response body; `broadcastOnQueue` pushes SDK stream
event objects onto a channel. These are different wires and do not compose — the SSE endpoint
goes away for the copilot. Other features in the panel that use the Vercel protocol are
unaffected and must stay untouched.

## Scope

**In:**
- `broadcastOnQueue` execution on a dedicated `ai` queue
- Private channel per conversation, authorized against the participant
- Tool events excluded from broadcast
- `useCopilotStream` composable in the panel consuming Echo
- Approval pause surfaced over the channel and resolved through a decisions endpoint
- Worker configuration and the timeout ladder
- Failure surfacing

**Out:**
- Usage metering (task 03)
- Rewriting the other Vercel-protocol features
- A custom Vercel `ChatTransport` over Echo — explicitly rejected, see Implementation notes

## Schema changes

None. Idempotency uses a client-supplied id held in cache:

```
cache key: copilot:dispatch:{conversation_id}:{client_message_id}   TTL 10m
```

A repeated POST with the same `client_message_id` returns the original `202` without
dispatching again. This matters because the panel will retry on a flaky connection and a
duplicate dispatch burns tokens and may re-execute tools.

## Implementation notes

### Dispatch

```php
Route::post('/api/copilot/conversations/{conversation}/messages',
    function (Request $request, Conversation $conversation) {
        Gate::authorize('view', $conversation);

        $validated = $request->validate([
            'message'           => ['required', 'string', 'max:8000'],
            'client_message_id' => ['required', 'uuid'],
        ]);

        return $this->accepted(
            CopilotDispatcher::dispatchTurn($conversation, $request->user(), $validated)
        );
    });
```

`App\Support\Ai\CopilotDispatcher` is a static helper under `App\Support\` — same tier as
`BillingMath` and `ContractBilling`, **not** a service class. It checks the idempotency key,
stamps `Context`, and calls:

```php
(new CrmCopilotAgent($employee))
    ->continue($conversation->id, as: $employee)
    ->broadcastOnQueue($message, new PrivateChannel("copilot.{$conversation->id}"));
```

Stamp correlation into `Context` **before** dispatch — it propagates into the queued job
automatically, which is how the worker knows the actor without a session. Task 03 consumes
these keys:

```php
Context::add([
    'ai_call_id'  => $callId,
    'employee_id' => $employee->id,
    'ai_purpose'  => 'copilot',
    'request_id'  => request_id(),
]);
```

The `202` body returns `{ call_id, conversation_id, channel }` so the panel knows what to
subscribe to.

### Channel authorization

```php
Broadcast::channel('copilot.{conversationId}', function (Employee $employee, string $conversationId) {
    $conversation = Conversation::find($conversationId);

    return $conversation
        && $conversation->participant_type === 'employee'
        && $conversation->participant_id === $employee->id;
});
```

Reuse the `ConversationPolicy` from task 01 rather than duplicating the predicate. Broadcast
auth must run on the Sanctum guard the panel uses, not the default web guard — verify
`config/broadcasting.php` and the auth endpoint middleware.

### Excluding tool events

```php
#[WithoutBroadcasting(ToolCall::class, ToolResult::class)]
class CrmCopilotAgent implements Agent, Conversational, HasTools
```

Planned tool results run 4–6k tokens of JSON, well past the ~10KB message ceiling most
broadcasting platforms enforce; oversized events cause broadcasting to fail outright.
Excluded events are still persisted to `agent_conversation_messages`, so the panel loads full
tool data after the stream completes.

The panel still needs to show *that* a tool ran while it is running. Broadcast a small custom
event from an `InvokingTool` listener carrying only `{ tool_name, call_id }` — never
arguments, which may contain contact PII.

### Panel transport — composable, not `ChatTransport`

**Do not write a custom Vercel `ChatTransport` over Echo.** The protocol is designed around
reading a `fetch` body; adapting it to a push channel means hand-writing a bridge against an
interface that moves between major versions of the `ai` package. Instead, build a composable
that consumes Echo directly:

```ts
// app/composables/useCopilotStream.ts
export function useCopilotStream(conversationId: Ref<string>) {
  // subscribes to `copilot.${conversationId}` on the private channel
  // accumulates text deltas into a reactive assistant message
  // exposes: messages, status, pendingApprovals, error
}
```

This is more idiomatic to the panel anyway — Pinia for state, `useXxx` composable naming,
`useApi()` for the REST calls. The other Vercel-protocol features keep their `useChat` usage
untouched.

**Enumerate the broadcast event names empirically.** The SDK's stream event classes are not
all documented; log every event in dev for one turn and build the mapping from what actually
arrives rather than guessing class names. Record the resulting list in `app/types/copilot.ts`
as a discriminated union.

### Approvals

Tool approval is supported by `broadcastOnQueue`. A pause arrives as a
`tool_approval_request` event on the channel; for queued agents the SDK also dispatches a
`ToolApprovalRequested` event server-side, which is the hook for a notification or an inbox
badge if the operator has navigated away.

Resolution endpoint:

```
POST /api/copilot/conversations/{conversation}/decisions
     { decisions: { "call_abc": { action: "approve" },
                    "call_def": { action: "reject", result: "..." } } }
```

Handler authorizes, then continues with `Decisions::from([...])`. Every pending call must
receive a decision or an `ApprovalMismatchException` is thrown; use `rejectRemaining()` as
the default when the panel submits a partial set. A rejection *with* a result is returned to
the model so it can keep responding; a rejection without one stops the generation loop.

One behaviour to handle explicitly: the SDK stores an approved tool's result **before**
asking the model to continue. If generation then fails, the approval is already resolved —
resume with a normal text prompt, never by resubmitting the same decisions.

### Worker configuration

Dedicated queue so a slow agent never blocks transactional email:

```
php artisan queue:work --queue=ai --timeout=180 --tries=1 --backoff=0
```

The timeout ladder, tightest last:

| Layer | Value |
|---|---|
| Agent `#[Timeout]` | 120s |
| `queue:work --timeout` | 180s |
| `retry_after` in `config/queue.php` | 240s |

`--tries=1` is not negotiable. A retried agent job re-burns tokens and may re-execute tools
that already committed. Fail loudly instead.

Add an `ai` worker container to the deployment compose file alongside the existing services.

### Failure surfacing

Implement `failed()` on the queued job path to broadcast a terminal `copilot.failed` event
with a translatable error key, so the panel shows a real error instead of a spinner that
never resolves. Task 03 hangs the usage settle off the same hook.

## API surface

```
POST   /api/copilot/conversations/{conversation}/messages    → 202 { call_id, conversation_id, channel }
POST   /api/copilot/conversations/{conversation}/decisions   → 202
GET    /api/copilot/conversations/{conversation}             unchanged (task 01)
```

The SSE route for the copilot is removed. Confirm by grep that no other feature routes
through it before deleting.

## Panel surface

- `useCopilotStream(conversationId)` composable; Echo subscription lifecycle tied to the
  component, unsubscribing on unmount and on conversation switch
- Streaming assistant message with a typing indicator
- Tool-running indicator from the lightweight `InvokingTool` broadcast
- Pending approval card: tool name, reason, arguments summary, Approve / Reject with an
  optional reason
- Terminal error state from `copilot.failed`
- Reconnect handling: on Echo reconnect, refetch the conversation to recover any events
  missed while disconnected — this is the failure mode that will actually bite users
- i18n under `copilot.stream.*` and `copilot.approvals.*` in `en.json`, `es.json`, `fr.json`

## Invariants

- No `app/Services/` — `CopilotDispatcher` lives under `App\Support\Ai\`.
- Panel: i18n for all strings, `Array<T>`, `useApi()` for HTTP.
- Tool arguments and results are never broadcast; PII stays out of the WebSocket payload.
- Approval decisions are authorized against the conversation participant on every call.

## Acceptance criteria

- [ ] Sending a message returns `202` and the turn executes in a queue worker.
- [ ] The panel renders streamed text from Echo; no SSE request appears in the network tab.
- [ ] Closing the tab mid-turn does not abort the run; reloading shows the complete message.
- [ ] Employee B cannot subscribe to Employee A's channel (broadcast auth returns 403).
- [ ] A duplicate POST with the same `client_message_id` does not dispatch a second turn.
- [ ] Tool call and result events are absent from the WebSocket payload but present in the
      conversation on reload.
- [ ] An approvable tool pauses the run, renders a card, and resumes on approve.
- [ ] Rejecting with a reason lets the model continue; rejecting without one ends the turn.
- [ ] A turn exceeding the agent timeout produces a `copilot.failed` event, not a hung UI.
- [ ] Echo reconnect after a dropped connection recovers the conversation state.
- [ ] Other Vercel-protocol features in the panel are unchanged and still work.

## Tests required

| Test | Asserts |
|---|---|
| `CopilotDispatchTest::message_dispatches_queued_agent` | `assertQueued`, 202 shape |
| `CopilotDispatchTest::duplicate_client_message_id_dispatches_once` | Idempotency |
| `CopilotChannelTest::participant_may_subscribe` | Channel auth true |
| `CopilotChannelTest::non_participant_may_not_subscribe` | Channel auth false |
| `CopilotApprovalTest::approvable_tool_pauses_turn` | `fakeWithPendingApprovals`, pending state |
| `CopilotApprovalTest::approve_resumes_and_executes` | Tool ran once |
| `CopilotApprovalTest::partial_decisions_reject_remaining` | No `ApprovalMismatchException` |
| `CopilotApprovalTest::decisions_authorized_against_participant` | 403 for other employee |
| `CopilotFailureTest::failed_job_broadcasts_terminal_event` | Terminal event dispatched |

Use `CrmCopilotAgent::fake()` with `preventStrayPrompts()` throughout so no test reaches a
provider.
