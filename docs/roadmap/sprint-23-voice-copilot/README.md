# Sprint 23 — Vocal Bridge voice for the CRM copilot

Voice input and output for the existing employee copilot, using Vocal Bridge
**AI Agent Integration** mode. The panel owns the VB session. Copilot stays on
`CopilotDispatcher`, the `ai` queue, `private-copilot.{id}` Reverb, and
click-only tool approvals (invariant 60).

Dashboard config that the integration needs: [`vb-config.json`](vb-config.json).
Manual QA (panel has no test runner): [`MANUAL-QA.md`](MANUAL-QA.md).

## Ratified

| # | Decision |
|---|---|
| D-V1 | Adaptive response mode. VB prompt forbids altering figures, dates, names, unit codes, amounts. |
| D-V2 | Filler-then-push via App→Agent `copilot_answer_ready`. Promise always settles. |
| D-V3 | Voice continues the active `CopilotConversation`. |
| D-V4 | Tool approvals are click-only, never voice. |

## Non-goals

- Agent→App navigation / opening records
- Voice for customer-facing agents (`AgentConversation`)
- Per-locale VB agents (v1: `language: multi`)
- Recording storage / call review in the panel (VB dashboard covers it)
