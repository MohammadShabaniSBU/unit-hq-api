# Sprint 34 — Template assistant

## Goal

An operator describes an email in a chat panel next to the builder and gets a
valid, hand-editable v2 block document on the canvas. They refine it turn by turn
("make the tone softer", "put old and new rate side by side") and can undo any
turn. Email channel, Blocks mode.

The assistant is an **internal agent beside the copilot**, not a copilot tool set
and not a customer-facing agent from `14-ai-agents.md`.

## Design decisions (lock at kickoff)

| # | Decision | Rationale |
|---|---|---|
| T1 | The assistant edits the document **only through typed ops** — the same `BlockOp` vocabulary the canvas uses (S33-04). Never free HTML. | One renderer owns client compatibility; every op is validated; undo is the op inverse; output stays hand-editable. |
| T2 | **Separate agent class** `App\Ai\Agents\TemplateAssistantAgent` with its own tools and prompt. | Different job, context and tools from `CrmCopilotAgent`; mixing them bloats the copilot prompt and makes both harder to test. |
| T3 | Conversation storage reuses the **SDK conversation store** (`copilot_conversations` / `copilot_conversation_messages`, `config/ai.php`). A link table ties a conversation to a template family + locale. The copilot sidebar excludes linked conversations. | No second chat store. **Not** `agent_conversations` — that is the customer-agent runtime (audience/verification CHECKs). |
| T4 | Turns run like copilot turns: `POST` returns 202 `{call_id, channel}`, the agent runs on the `ai` queue via `broadcastOnQueue` on a private channel, idempotent by `client_message_id`. | Same mechanics as `CopilotDispatcher`; the panel already has a turn bus (`copilotTurnBus.ts`). |
| T5 | Tools operate on a **working document stored on the turn row**, starting from the document the panel posted. The final document and the ops with inverses are read back by the panel after the stream ends. | Queued job; the result must outlive the job and be inspectable. |
| T6 | The assistant **never saves the draft and never publishes.** Its result is editor state until the employee presses Save. | Keeps S29's "Save writes the draft; sends use the latest published version". |
| T7 | Context contains **no CRM, billing or message records**. Tokens and sample values come from `TokenCatalog` (invariant 92). The agent has no read tools into business data. | No contact PII in conversation messages; sidesteps the open redaction gap for AI conversations in `10`. |
| T8 | Spend through `ai_usage_events` with `ai_purpose = template_assist` (existing `MetersUsage` middleware + `Context`). Daily per-employee turn limit in config. | Invariant 48; existing telemetry and AI usage tab. |
| T9 | New permission `TemplateAssistUse = 'template_assist.use'`, required **with** `template.manage`. | Owners can switch it off per role. |
| T10 | Hidden for `document` and `sms` channels and in HTML mode this sprint. | Scope; contracts need legal review; HTML mode is S35. |

## Prerequisites

- S33 merged: document v2, `BlockOp` + `applyOps` in the panel, `TokenCatalog`,
  brand settings, new blocks, undo stack.
- An AI provider account configured (Settings → AI providers). The agent resolves
  provider and model through `AiProviderRegistry` like the copilot.

## Exit criteria

- [ ] From an empty draft, the prompt "rate increase notice, show old and new rate
      side by side, friendly tone" produces a valid v2 document with a `50_50`
      section, correct catalogue tokens and one button, in one turn.
- [ ] Three follow-up turns refine it without touching the canvas; each turn is one
      undo entry.
- [ ] Editing the canvas while a turn runs never loses the manual edit (rebase or
      conflict view).
- [ ] Nothing reaches the draft until **Save**; nothing goes live until **Publish**.
- [ ] Assistant conversations do not appear in the copilot sidebar.
- [ ] `ai_usage_events` rows with purpose `template_assist` appear in Settings → AI
      providers → Usage.
- [ ] No `copilot_conversation_messages` row produced by the assistant contains a
      real contact's name, email or balance (test with a seeded contact).

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Schema, permission & config](./00-schema-permission-config.md) | 0.5 day |
| 01 | [Assist context](./01-assist-context.md) | 1 day |
| 02 | [Agent & document tools](./02-agent-and-tools.md) | 2.5 days |
| 03 | [Assist API, dispatch & usage](./03-assist-api-and-dispatch.md) | 1.5 days |
| 04 | [Panel: assistant tab & op applier](./04-panel-assistant.md) | 3 days |
| 05 | [Entry points & starters](./05-entry-points-and-starters.md) | 1 day |
| 06 | [Invariants, docs & tests](./06-invariants-and-docs.md) | 0.5 day |

**Total ≈ 10 days.** 00 → 01 → 02 → 03 are sequential on the API. 04 can start on
day 1 against recorded turn fixtures (`tests/Fixtures/template-assist/turns/*.json`,
written in 02). 05 needs 04.

## Out of scope

- Translation, images in chat, HTML mode, eval suite, quality report, rollout toggle (S35).
- SMS, WhatsApp and contract-document assistants.
- Any assistant write to the draft, version or publish state (T6).

## Next

S35 — Template assistant breadth & quality.
