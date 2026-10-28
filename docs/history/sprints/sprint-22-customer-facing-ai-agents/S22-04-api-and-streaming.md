# S22-04 — API surface, SSE streaming, telemetry

**Repo:** `unit-hq-api`
**Depends on:** `S22-01`, `S22-02`, `S22-03`
**Blocks:** `S22-05`

## Goal

Expose the runtime over HTTP on **real endpoints**. No `/api/demo/*` namespace —
that becomes a second code path someone maintains and then deletes. The demo is
a value in `origin`, not a route prefix.

## Routes

All inside the `auth:sanctum` group (invariant 42), all reaching a permission
decision via `RoutePermissions` (invariant 43). Permission:
`Permission::AiAgentUse`.

| Method | Route | Purpose |
|---|---|---|
| `GET` | `/api/ai/agents` | Picker data: active, non-archived agents |
| `POST` | `/api/agent-conversations` | Start a conversation |
| `GET` | `/api/agent-conversations` | List, filterable by `origin`, `ai_agent_id`, `state` |
| `GET` | `/api/agent-conversations/{id}` | Detail: messages + invocations + handoffs |
| `POST` | `/api/agent-conversations/{id}/turns` | **SSE stream** — send input, receive the turn |
| `POST` | `/api/agent-conversations/{id}/close` | Close |
| `GET` | `/api/ai/demo-personas` | Contacts usable as demo principals (see below) |

Response shape via `ApiResponsable`: `{ message, data }`, `{ meta }` on the
paginated list. The SSE route is the sole exception and returns
`text/event-stream`.

### `POST /api/agent-conversations`

```json
{
  "agent_key": "support",
  "channel": "email",
  "origin": "demo",
  "contact_id": 412,
  "verification_level": "verified",
  "site_id": 3,
  "locale": "es"
}
```

Rules:

- `origin = demo` is **refused with 422** unless `config('ai.demo_enabled')` is
  true. One flag turns the whole surface off in production.
- `audience` is derived, not accepted from the client: `contact_id` present →
  `customer`; otherwise `internal`. Never trust the client for a trust boundary.
- `created_by_employee_id` is stamped from the authenticated employee.
- `verification_level` is accepted from the client **only** when
  `origin = demo`. For `inbox` / `webchat` in S23 it is derived from how the
  message arrived. Enforce this in the request rule now — it is exactly the
  kind of parameter that quietly stays client-controlled forever.
- Validate the contact exists and is not archived; `verified` requires a
  contact (the DB CHECK backs this, but fail with a readable 422 first).

### `POST /api/agent-conversations/{id}/turns`

Body: `{ "input": "how much do I owe?" }`

Returns `text/event-stream`. Refuses (409) when `state` is not `active`.

Event vocabulary — machine keys, panel translates:

| Event | Payload |
|---|---|
| `turn.started` | `{ sequence }` |
| `token` | `{ delta }` |
| `tool.started` | `{ tool_key, arguments }` |
| `tool.finished` | `{ tool_key, status, denied_reason?, duration_ms, result_summary }` |
| `guardrail` | `{ guard, verdict, detail? }` — emitted on pass and on block |
| `handoff` | `{ reason, trigger_source, detail }` |
| `usage` | `{ input_tokens, output_tokens, estimated_cost, currency }` |
| `turn.completed` | `{ message_id, blocked_by?, state }` |
| `error` | `{ message }` |

Emit `guardrail` on **pass** as well as block. During prompt work, seeing the
grounding guard pass on every turn is how you learn to trust it.

Heartbeat comment every 15s to keep intermediaries from closing the connection.
Disable output buffering and set `X-Accel-Buffering: no` for the nginx path.

Streaming and PHP-FPM: use a `StreamedResponse` with explicit `flush()`. Verify
against the docker-compose PHP-Nginx setup, not just `artisan serve` — buffering
differences bite here and only here.

### `GET /api/ai/demo-personas`

Contacts from the seeded world usable as demo principals, with enough context
for the picker: name, site, whether they have an active contract, whether they
have an open balance, whether they have an open delinquency case.

Gated on `config('ai.demo_enabled')`. This is the one genuinely demo-shaped
endpoint; keep it small and clearly named so it is obvious what to delete.

## Authorization

- `Permission::AiAgentUse` on all routes, registered in `RoutePermissions`.
- `AgentConversationPolicy` — an employee may view a conversation they created,
  or any conversation if they hold a company-wide grant. Do not over-engineer;
  this is internal tooling, but it must reach a real decision or
  `PermissionCoverageTest` fails.
- `SubjectSite` mapping for `AgentConversation` — required, or
  `SubjectSiteCoverageTest` fails on the new morph entry if one is added. Map to
  `site_id` (nullable is fine; the test forbids *unmapped*, not *null-valued*).
  Fail-closed per invariant 45.

## Telemetry

Write `ai_usage_events` per model call, using the existing reserve/settle
lifecycle:

- reserve before the call with an estimate;
- settle after with real token counts;
- `ai_agent_id` + `agent_conversation_id` set, `employee_id` **null** for
  customer-audience conversations.

Estimated cost derives at read time from `ai_model_prices` — never stored, never
an in-place rate update (invariant 48). Never return a single summed cost across
currencies (invariant 30) — the `usage` SSE event carries one currency, and the
demo page shows a per-currency breakdown if more than one appears.

## Activity logging

Tier-2, channel `ai` (new channel — add it to the activity-log channel config
and the Settings toggle list):

| Event | Subject | Properties |
|---|---|---|
| `agent.conversation.started` | `AgentConversation` | `agent_key`, `channel`, `origin`, `audience`, `verification_level` |
| `agent.handoff` | `AgentConversation` | `reason`, `trigger_source` |
| `agent.guardrail.blocked` | `AgentConversation` | `guard`, `blocked_by` — **never the draft text** |

Not every turn. Turns live in `agent_conversation_messages`; the activity log is
not a transcript.

Tier-1 `SystemEvent` for `ai.turn.failed` on driver errors and timeouts.

## Rate limiting

Throttle the turns endpoint per employee (`throttle:ai-turns`, e.g. 30/min).
A demo page with a stuck retry loop should not be able to burn the month's
budget in an afternoon.

## Tests

- `RouteAuthCoverageTest` and `PermissionCoverageTest` green with the new routes.
- `origin = demo` refused when the flag is off.
- `verification_level` in the request body is ignored (not merely unused) for
  non-demo origins.
- `audience` cannot be set by the client.
- Turns endpoint returns 409 on a `handed_off` conversation.
- SSE integration test: assert event order for a tool-using turn —
  `turn.started` → `tool.started` → `tool.finished` → `token`* →
  `guardrail` → `turn.completed`.
- `ai_usage_events` written with `ai_agent_id` and null `employee_id`.

## Acceptance

- [ ] No route lives under a `demo` prefix.
- [ ] `config('ai.demo_enabled') = false` makes the demo path unreachable while
      leaving the runtime intact for S23.
- [ ] Streaming verified through the docker-compose nginx path, not only
      `artisan serve`.
- [ ] Every model call produces a settled `ai_usage_events` row, agent-attributed.
- [ ] Blocked drafts never appear in the activity log.
