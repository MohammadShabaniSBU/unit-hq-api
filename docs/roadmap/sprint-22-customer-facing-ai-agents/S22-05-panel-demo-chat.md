# S22-05 — Panel `/demo/chat`

**Repo:** `unit-hq-panel`
**Depends on:** `S22-04`

## Goal

A three-pane console. The right pane — the trace — is the actual deliverable of
this sprint. It is what convinces a sceptical room the agent is not guessing,
and it is where the team will live for the next six months of prompt work.

## Layout

```
┌──────────────┬─────────────────────────┬──────────────────────┐
│ Setup        │ Conversation            │ Trace                │
│              │  (channel-skinned)      │                      │
│ Agent        │                         │ ▸ tool.started       │
│ Channel      │  ┌───────────────────┐  │   billing.balance    │
│ Persona      │  │ assistant bubble  │  │   { contract_id: 8 } │
│ Verification │  └───────────────────┘  │ ▸ tool.finished ok   │
│ Site         │  ┌───────────────────┐  │   142 ms             │
│ Locale       │  │ operator bubble   │  │ ▸ guardrail grounding│
│              │  └───────────────────┘  │   pass (3 facts)     │
│ [Reset]      │                         │ ▸ usage 1.2k / 340   │
│ [New convo]  │  [ input ] [ send ]     │   ≈ €0.004           │
└──────────────┴─────────────────────────┴──────────────────────┘
```

Route: `pages/demo/chat.vue`. Nav entry under a **Demo** section, visible only
when the agents feature is enabled and the employee holds `ai_agent_use`.

## Left pane — setup

| Control | Source | Notes |
|---|---|---|
| Agent | `GET /api/ai/agents` | support / sales |
| Channel | static enum | email / sms / whatsapp / webchat |
| Persona | `GET /api/ai/demo-personas` | shows name, site, has-contract, has-balance, has-delinquency flags |
| Verification | static enum | anonymous / channel_asserted / verified |
| Site | existing site list | prefills from persona |
| Locale | en / es / fr | drives the agent's reply language |

Changing any of these starts a **new conversation** — do not mutate an existing
one. Show a confirm if the current conversation has turns.

Persona is disabled when verification is `anonymous`. Verification `verified` is
disabled when no persona is selected (the API rejects it anyway; do not let the
UI construct a request the API must refuse).

## Centre pane — channel-skinned conversation

The skin is the point. Same answer, four shapes:

- **email** — subject line above the body, HTML rendering, signature block.
- **sms** — narrow bubbles, live character count and segment count with the
  encoding (GSM-7 / UCS-2) shown, cap indicator.
- **whatsapp** — bubble styling, a chip showing session-vs-template advisory
  from the `guardrail` event.
- **webchat** — widget-shaped column, compact.

Streaming: tokens append live. A tool call in progress shows an inline
"consulting…" affordance with the tool's translated label, replaced when
`tool.finished` arrives.

Blocked drafts render as a distinct suppressed-message state — struck-through or
muted, with the guard name — **not** as a normal assistant message. Seeing what
would have been sent, and why it wasn't, is the most instructive thing on the
screen.

Handoffs render as a full-width banner: *Would escalate to a human — reason:
delinquency (rule)*. Once handed off, the composer disables with an explanation.

## Right pane — trace

Chronological event log for the current conversation. Each entry expandable:

- **tool call** — key, arguments (JSON viewer), status, denied reason, duration,
  `result_summary`, full `result` behind a toggle;
- **guardrail** — guard name, pass/block, detail (offending token on a grounding
  block);
- **handoff** — reason, trigger source, detail;
- **usage** — tokens in/out, latency, estimated cost with its currency;
- **running totals** at the top: turns, total tokens, total estimated cost per
  currency, tool calls, blocks.

Never sum cost across currencies (invariant 30) — show one line per currency
even if that looks odd. It will look odd exactly once, in front of the right
person, and that is a feature.

Copy-as-JSON button on the whole trace. Prompt debugging happens in tickets.

## Streaming client — the thing that will bite

`EventSource` cannot set an `Authorization` header. Do **not** work around it
with a query-string token.

Use `fetch` + `ReadableStream`:

```ts
const res = await fetch(url, {
  method: 'POST',
  headers: { ...authHeaders, 'Content-Type': 'application/json', Accept: 'text/event-stream' },
  body: JSON.stringify({ input }),
})
const reader = res.body!.getReader()
// decode, split on \n\n, parse `event:` / `data:` lines
```

Wrap it in `useAgentStream()` so the page stays declarative. Keep header
construction consistent with `useApi()` — extract the header logic rather than
duplicating it, or a future auth change breaks only this page.

Handle: abort on unmount and on send-cancel, reconnect-free (a dropped stream
means a failed turn — surface it, do not silently retry), and a client-side
timeout matching `turn_timeout_ms`.

## Composables and types

- `useAgentChat()` — conversation lifecycle, message list, send.
- `useAgentStream()` — SSE parsing, typed events.
- `useAgentList()` — agent picker.
- `useDemoPersonaList()` — persona picker.

Types in `app/types/agents.ts`. `Array<T>`, never `T[]`. Discriminated union for
the stream events keyed on the event name — this is where TypeScript earns its
keep; do not type it as `any`.

## i18n

Every string through `locales/en.json` / `es.json` / `fr.json`. That includes
tool labels, guard names, handoff reasons, denial reasons, and channel names —
the API returns machine keys, the panel translates. A raw `grounding_failure`
on screen is a bug.

Namespace: `demo.chat.*`, `ai.tools.*`, `ai.guards.*`, `ai.handoff_reasons.*`.

## Out of scope

Conversation history browsing, transcript export beyond copy-as-JSON,
side-by-side agent comparison, prompt editing in the UI. All tempting, all S23+.

## Tests

CI is `bun run lint` + `bun run typecheck` only — so the type work carries the
weight. Make the stream event union exhaustive with a `never` check in the
handler switch so an added backend event fails typecheck rather than being
silently ignored.

## Acceptance

- [ ] All five presentation scenarios (README) run end-to-end on the page.
- [ ] Switching channel visibly changes the rendering and the guard advisories
      for the same underlying answer.
- [ ] Flipping verification from `verified` to `channel_asserted` and re-asking
      produces a visible denial in the trace, not a silent difference.
- [ ] A blocked draft is visually distinct from a sent one.
- [ ] No hardcoded strings; `bun run lint` and `bun run typecheck` green.
