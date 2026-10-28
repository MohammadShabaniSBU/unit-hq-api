# S34-03 — Assist API, dispatch & usage

## Context

Copilot turns go through `App\Support\Ai\CopilotDispatcher`: cache-based
idempotency on `client_message_id`, `Context::add([... 'ai_purpose' => 'copilot' ...])`,
`->continue($conversationId, as: $employee)->broadcastOnQueue($prompt, PrivateChannel)`
on the `ai` queue, and a 202 `{call_id, conversation_id, channel}` payload. The
assistant mirrors that so the panel can reuse its turn bus.

## Scope

**In:** session endpoints, turn dispatch, turn read, turn status reporting, daily
limit, copilot list exclusion, failure events, activity on publish.
**Out:** panel (04).

## Schema changes

None.

## Implementation notes

- `App\Support\Templates\Assist\TemplateAssistDispatcher::dispatchTurn(TemplateAssistSession, Employee, array $validated): array`
  1. Idempotency: if a turn with `(session_id, client_message_id)` exists, return its
     payload.
  2. Daily limit: count today's turns for the employee (site-local day is irrelevant;
     use UTC day, document it). Over → 429 `assist.daily_limit_reached`.
  3. Validate the posted `document` with `EmailBlockDocument::validate()` (must be v2)
     and that `template_version_id` is the family's **current draft** (409
     `assist.draft_changed` otherwise — the panel reloads).
  4. Compute `base_hash` from canonical JSON (sorted keys, no whitespace). Never trust
     a client hash.
  5. Insert the turn (`status = running`, `working_document = base_document`).
  6. `Context::add([... 'ai_purpose' => 'template_assist', 'conversation_id' => …])`,
     dispatch `TemplateAssistantAgent($employee, $turnId)` with
     `->continue($session->conversation_id, as: $employee)->broadcastOnQueue($message, new PrivateChannel("template-assist.{$session->id}"))->onQueue('ai')`.
  7. Return `{call_id, turn_id, session_id, channel}`.
- Completion: listen for the SDK's agent-finished / failed events for this agent
  class (same hook `RecordAgentUsage` uses) → set turn `completed` or `failed`
  (`error_key` from the exception class map: provider error, timeout,
  budget). Broadcast a final `template-assist.turn.finished` event
  `{turn_id, status}` on the same channel.
- Broadcast channel authorisation in `routes/channels.php`: the session's employee only.
- Copilot exclusion: `CopilotController::index` adds
  `whereNotIn('id', TemplateAssistSession::select('conversation_id'))`. Also guard
  `show`/`storeMessage` against assistant conversations (404) so a copilot turn can
  never run in an assistant conversation.
- New sessions: one conversation per session, created through the SDK store with
  `participant_type = employee`, title `"{family name} · {locale}"`.
- Publish activity: the existing `template.published` Tier-3 row gains
  `properties.ai_assisted` (bool) and `properties.ai_turns` (int: turns with
  status `applied` on the published version's draft). No per-turn activity rows.
- Tier-1 `SystemEvent`: `template.assist.failed` `{turn_id, error_key, model}`.

## API surface

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `template-families/{f}/assist/session?locale=` | `template.manage` + `template_assist.use` | Latest session for (family, locale, me) with messages (from the SDK store) and turns (`id, status, op_summary, subject_change, error_key, created_at`); `null` when none |
| `POST` | `template-families/{f}/assist/sessions` | same | `{locale}` → new session (fresh chat) |
| `POST` | `template-assist/sessions/{s}/turns` | same | `{client_message_id, message ≤ max_prompt_chars, template_version_id, document, selection?}` → 202 |
| `GET` | `template-assist/turns/{t}` | same, owner of session | `{status, base_hash, ops, working_document, subject_change, op_summary, error_key}` |
| `POST` | `template-assist/turns/{t}/status` | same | `{status: applied \| conflict \| discarded \| undone}` — forward-only per 00 |

All routes registered in `RoutePermissions`. Responses use `ApiResponsable`.

## Panel surface

None (04).

## Invariants

- **45** fail-closed permission; **48** usage via `ai_usage_events`.
- **T6** — no endpoint here writes to `template_versions` or `template_variants`.

## Acceptance criteria

- [ ] Posting the same `client_message_id` twice dispatches once.
- [ ] A turn against a stale draft id returns 409 `assist.draft_changed`.
- [ ] Turn 101 of the day returns 429.
- [ ] A failed provider call marks the turn `failed`, settles/releases usage and
      writes the system event.
- [ ] Copilot `index` never lists assistant conversations; copilot `storeMessage` on
      one returns 404.
- [ ] Another employee cannot read a turn or subscribe to the channel.
- [ ] Publishing a version edited with two applied turns writes
      `ai_assisted: true, ai_turns: 2`.
- [ ] No endpoint in this task modifies a template version or variant (grep + test).

## Tests required

| Test | Asserts |
|---|---|
| `TemplateAssistDispatchTest::idempotent_client_message` | One job |
| `TemplateAssistDispatchTest::stale_draft_conflict` | 409 |
| `TemplateAssistDispatchTest::daily_limit` | 429 |
| `TemplateAssistDispatchTest::usage_purpose_template_assist` | `ai_usage_events.purpose` |
| `TemplateAssistTurnTest::status_reporting_forward_only` | Transitions via API |
| `TemplateAssistAuthTest::owner_only_turn_and_channel` | Isolation |
| `CopilotControllerTest::excludes_assistant_conversations` | Sidebar |
| `TemplatePublishTest::records_ai_assisted` | Activity properties |
| `TemplateAssistNoWritesTest::endpoints_never_touch_templates` | T6 |
