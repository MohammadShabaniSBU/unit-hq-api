# S28-01 — The bridge endpoint, its auth, and session mapping

**Depends on:** S28-00
**Blocks:** S28-02, S28-03, S28-06, and the launch gate
**Touches:** `unit-hq-api`

## Problem

Vocal Bridge's AI-agent integration calls a URL we host. Its foreground agent
decides a question is domain-specific, POSTs a `query` with a `turn_id`, waits
up to a configured timeout, and speaks what comes back. We have no such
endpoint, no way to authenticate the caller, and no way to tie a Vocal Bridge
session to an `agent_conversations` row across turns.

Every `agent-conversations` route sits inside `auth:sanctum`. The obvious
shortcut — mint a Sanctum token, paste it into the Vocal Bridge dashboard —
hands a third-party SaaS an **employee identity with the entire API behind
it**. Not the endpoint: the API.

## What to build

### The route

Public, in the allowlist block of `routes/api.php`, with the comment
invariant 42 requires naming what authenticates it:

```
POST /api/voice/bridge/{bridge_token}
```

`bridge_token` is a crypto-random path token, the same idiom as
`webhooks/{provider}/{webhook_url_token}` and `offers/token/{token}`. It
identifies *which* bridge configuration is calling — one per number, so a
rotated or leaked token can be revoked without touching the others.

**Plus an HMAC over the raw body**, carried in a header Vocal Bridge sets via
its custom-header setting in the dashboard. Compare with `hash_equals`. The
path token alone is a bearer secret in a URL that will end up in logs; the
HMAC is what makes replay and log-leak survivable. Reject with 401 and log an
`ai.voice.bridge_auth_failed` system event — a failing HMAC is either
misconfiguration or an attack, and both are worth seeing.

Rate-limit the route independently. It is the only unauthenticated path in
the system that reaches the agent runtime.

### `voice_sessions`

| Column | Notes |
|---|---|
| `bridge_session_id` | Vocal Bridge's session identifier, unique |
| `agent_conversation_id` | FK, created on first delegation |
| `bridge_token_id` | which configuration answered, so a number maps to a site |
| `caller_number` | normalised E.164, nullable (withheld) |
| `contact_id` | nullable, the caller-ID match from S28-00 |
| `site_id` | resolved from the bridge configuration |
| `started_at`, `ended_at` | |

`copilot_voice_sessions` is the shape to copy. Do not extend that table —
its principal is an employee and its lifecycle is browser-side; sharing a
table would put two different security models in one place.

### Request handling

1. Verify token and HMAC.
2. Find or create the `voice_sessions` row by `bridge_session_id`.
3. Find or create the `agent_conversations` row with `channel=voice`,
   `origin=voice`, site from the bridge configuration, contact and
   verification from S28-00's caller-ID resolution.
4. **Idempotency on `turn_id`.** Store it unique per session and let a replay
   hit the constraint, then return the previously produced answer rather than
   running a second turn. `RespondWithAgent` already catches
   `UniqueConstraintViolationException` on `subject_message_id`; follow that
   pattern. Vocal Bridge's `late_response_behavior` and retry semantics make
   a duplicate delivery a normal event, not an exception.
5. Run the turn. Return the draft.

### The response contract

Return the agent's text and nothing that needs interpreting. Specifically:

- The answer text, already within `ChannelProfile::Voice`'s 600-character
  ceiling.
- A transfer signal when the turn produced a handoff — S28-03 defines its
  shape.
- Nothing else. No tool names, no ids, no confidence scores. Every field we
  return is a field the foreground model can decide to mention.

### Failure shape

On any failure — timeout, guard block with no redraft, exception — return a
short fixed sentence that hands the call to a human, and log it. Never return
an error string the foreground agent will read aloud. A caller hearing a stack
trace is worse than a caller hearing "let me put you through to someone".

## Acceptance criteria

- [ ] Route lives in the public allowlist with the invariant-42 comment.
- [ ] Valid token + valid HMAC → 200; valid token + bad HMAC → 401 and one
      `ai.voice.bridge_auth_failed` event; unknown token → 401.
- [ ] Migration up/down on Postgres and SQLite.
- [ ] First delegation creates a session and a conversation; second
      delegation on the same `bridge_session_id` reuses both.
- [ ] Replayed `turn_id` returns the same answer and does **not** run a second
      turn — assert on the `agent_conversation_messages` count.
- [ ] A turn that throws returns the fixed handoff sentence, 200, with the
      exception logged.
- [ ] Route is rate-limited; the limit is asserted in a test.
- [ ] No response field carries an entity id.

Introduces **D-AI-25** (S28-06).

## Out of scope

- Minting Vocal Bridge access tokens. That is the copilot's
  `POST /api/copilot/voice/token` pattern and it is not needed here — the
  bridge calls us, we never call it.
- The `a2a` protocol option. Use `http`; revisit only if Vocal Bridge's
  agent-to-agent mode buys something the plain POST doesn't.
- Streaming a partial answer back. Vocal Bridge waits for a complete
  response; partials are a Tier 2 concern.
