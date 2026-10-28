# Voice

Part of the customer-facing agent docs. Map: [README](README.md).


## Voice (Vocal Bridge)

Employee copilot only. Customer-facing agents (`AgentConversation`) are a
non-goal. The panel owns the Vocal Bridge session; VB handles mic, STT, turn
detection, TTS and barge-in. Copilot stays on `CopilotDispatcher` / the `ai`
queue / `private-copilot.{id}` Reverb. Tool approvals stay click-only
(invariant 60).

```
mic → VB (STT + endpointing)
    → vb.onAIAgentQuery(query)
    → POST /api/copilot/conversations/{id}/messages  { source: voice }  → 202
    → Reverb: stream_start → text_delta… → stream_end
    → resolve(promise) with accumulated text, or sendAction('copilot_answer_ready')
    → VB speaks it (adaptive mode)
```

`source` is accepted on both `POST …/messages` and `POST …/decisions`. The
post-approval continuation is a separate generation; without `source: voice`
the resumed half comes back as markdown and gets spoken. `CrmCopilotAgent`
takes `voice: bool` and concatenates a spoken-form block onto the existing
instructions. The panel does not post-process the text.

`AgentChannel::Voice` + `ChannelProfile` exist so `ChannelProfileTest` stays
exhaustive. Copilot does **not** read `ChannelProfile`. `POST /api/agent-conversations`
rejects `channel=voice` (422) — customer-facing voice is not this sprint.

### Turn lifecycle: `settle` vs `deliver`

`app/utils/copilotTurnBus.ts` is a module-level emitter. The copilot store
keeps a **separate `voiceBuffer`**, reset on every `stream_start`.
`ensureStreamingAssistant()` reuses the trailing assistant message, so a
post-approval continuation appends into the same `TextPart`. Reading the
message would re-speak the pre-approval half. The buffer resets per stream,
so the push after approval carries only the continuation.

- **`settle`** — resolve the `onAIAgentQuery` promise once (answer or hang-guard filler).
- **`deliver`** — the real answer. Resolves if we have not spoken yet; otherwise
  `sendAction('copilot_answer_ready', { text })`. A turn is spoken exactly once
  via `resolve` and at most once more via `sendAction`, never both with the
  same text.

`ANSWER_DEADLINE_MS = 25_000` is a **hang-guard, not a UX filler**. VB
AI-agent mode already fills while waiting on our query. The deadline exists
so the promise settles if Reverb drops, against `CrmCopilotAgent`
`#[Timeout(120)]`. Abandoned approvals clear after 5 minutes.

Switching `activeConversationId` mid-turn (the list stays reachable — D-V3)
delivers `copilot.voice.interrupted` and clears the turn. Closing the
slideover does not drop Reverb: `useCopilotStream` lives on `default.vue`.

Auth is `tokenProvider` (verified `@vocalbridgeai/sdk@0.1.1` `.d.ts`), so a
later duration bump can re-mint. The API key stays server-side
(`POST /api/copilot/voice/token`, permission `copilot_voice.use`).

