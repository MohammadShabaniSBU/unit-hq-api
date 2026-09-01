# S27-04 — Contact verification: making `verified` reachable

**Depends on:** nothing (lands before S27-01's re-record)
**Blocks:** S27-01, S27-05
**Touches:** `unit-hq-api`

## Problem

No production principal ever reaches `VerificationLevel::Verified`.

- `AgentConversationController::resolveVerification()` returns
  `ChannelAsserted` when a contact id is supplied and `Anonymous` otherwise,
  for every origin except `demo`.
- `RespondWithAgent` constructs `AgentPrincipal::channelAsserted(...)`
  unconditionally for inbound.
- `PrincipalPromotion::afterToolResult()` promotes anonymous →
  `channel_asserted` only, and returns early once the principal already
  satisfies that level.
- `AgentPrincipal::verified()` is reached from exactly two places:
  `AgentConversation::principal()` rebuilding a row whose stored level is
  already `verified`, and `EvalHarness`.

Nothing writes `verified`. So `billing.balance`, `billing.next_charge`,
`billing.invoices`, `contract.summary` and `access.status` are unreachable
outside `origin = demo`, where `AiDemoPersonaController` and a client-supplied
`verification_level` stand in for authentication.

`PrincipalPromotion`'s own docblock names the fix: *"OTP verification for
webchat is what closes it."* `14-ai-agents.md` lists
`contact_verifications` / OTP under "deliberately not built — the
verification level is a demo toggle."

Without this task, S27-00 produces a unified agent holding five tools nobody
can reach. The merge is what makes the gap load-bearing.

## What to build

### Table `contact_verifications`

| Column | Type | Notes |
|---|---|---|
| `contact_id` | FK `contacts` | |
| `agent_conversation_id` | FK, nullable | the conversation that asked |
| `contact_channel_id` | FK `contact_channels` | **the channel the code went to** — never a value from the request |
| `code_hash` | string | sha256 of the code. The plaintext exists only in the outbound message |
| `attempts` | small int, default 0 | |
| `expires_at` | timestamp | |
| `consumed_at` | timestamp, nullable | single use |
| `created_at` | timestamp | append-only, no `updated_at` beyond attempts/consumed |

Partial unique on `(contact_id) WHERE consumed_at IS NULL AND expires_at > now()`
is wrong under Postgres (`now()` is not immutable) — enforce single-open-
challenge in the request path under a row lock instead, and index
`(contact_id, expires_at)`.

Config: TTL 10 minutes, 6 digits, max 5 attempts, max 3 issued codes per
contact per hour.

### The channel rule

The code goes to a `contact_channels` row that **already exists on the
contact**, chosen server-side. The customer never supplies a destination.
This is the whole security property: `channel_asserted` means "wrote from an
address we have on file", and verification means "still controls it".
Accepting a destination from the conversation turns the flow into an
attacker-nominated delivery and is worth a comment in the code saying so.

Delivery goes through `SmsSender` / `EmailSender` with a `SendContext` of
provenance `system` and class `transactional` (invariant 38). The agent never
writes a `messages` row. `channel_suppressions` still applies — a suppressed
`all`-scope address cannot receive a code, and the tool must return a
recovery affordance (invariant 65) telling the model to escalate rather than
retry.

### Two tools

Both under `App\Support\Ai\Tools\`, added to
`ConciergeAgentDefinition::toolKeys()`:

**`identity.request_code`** — `requiredVerification()` = `ChannelAsserted`,
`isWrite()` = true. No arguments beyond an optional channel *type*
preference (`email` | `sms`), never a value. Returns a display string naming
the masked destination (`•••• 4417`) so the model can say where the code
went without learning the number. Rate limits ride on
`agent_write_policies.max_per_conversation` / `max_per_day` — seed
`{mode: commit, max_per_conversation: 3, max_per_day: 10}`.

**`identity.verify_code`** — same floor, `isWrite()` = true, one argument
`code`. Compares against the open challenge under a row lock, increments
`attempts`, sets `consumed_at` on success. Returns ok/failed with a reason
code; never reveals whether a wrong code was close, expired, or for another
contact.

Both must be in `RuntimeOnlyTools` if that list governs what may be replayed
from a pending action — a verification is not an approvable intent.

### Promotion

Extend `PrincipalPromotion` with a second path, `afterToolResult()` on
`identity.verify_code` with an `ok` result:

- requires `$principal->audience === AgentAudience::Customer`
- requires `$conversation->contact_id` to equal the verified contact — a
  conversation cannot verify into a *different* contact
- writes `agent_conversations.verification_level = verified`
- logs `agent.conversation.principal_promoted` with `{from, to, contact_id,
  method: 'otp'}` through `AgentWriteAttribution`

The existing anonymous → channel_asserted path is untouched. Update the
docblock: the sentence about OTP being what closes the gap is now describing
the code below it, not a future sprint.

### Verification is not inherited

A new conversation with the same contact starts at `channel_asserted` again.
`resolveVerification()` keeps returning `ChannelAsserted` for a supplied
contact id; it must not look up "was this contact verified recently". A
verification is scoped to the conversation it was earned in. Say so in a
comment, because the shortcut is tempting and the failure is silent.

### What still never produces `verified`

- Caller ID (sprint 28's concern, written down here first).
- A self-stated email matching an existing contact — the existing
  `crm.create_contact` dedupe path stays capped at `channel_asserted`.
- `origin = demo`. The demo toggle keeps writing `verified` directly and
  keeps being the reason `demo` is excluded from every metric (invariant 59).

## Acceptance criteria

- [ ] Migration up/down, Postgres + SQLite.
- [ ] `identity.request_code` sends to a channel resolved from
      `contact_channels`; a request naming a destination is rejected at the
      schema gate (no such argument exists).
- [ ] Suppressed address → tool returns a machine error code + recovery
      affordance; no send attempted.
- [ ] Wrong code increments `attempts`; the 6th attempt fails closed and
      invalidates the challenge.
- [ ] Expired code fails; expiry checked at read time (invariant 13), no
      sweeper.
- [ ] Consumed code cannot be reused.
- [ ] On success the conversation's `verification_level` is `verified` and
      the next turn's `billing.balance` passes the verification gate.
- [ ] A verified conversation does not verify a second conversation for the
      same contact.
- [ ] `contact_verifications` added to `config/redaction.php` scope — see
      S27-06.

Introduces **D-AI-23** and **invariant 72** (S27-06).

## Out of scope

- Voice. A code read aloud on a call is a different threat model and belongs
  with sprint 28's caller-ID work.
- Any tenant login, session, or portal. This verifies a conversation, not a
  person, and creates no credential.
- Lowering any tool's verification floor. If a tool is at `verified` today it
  stays there; this task makes that level reachable rather than cheaper.
