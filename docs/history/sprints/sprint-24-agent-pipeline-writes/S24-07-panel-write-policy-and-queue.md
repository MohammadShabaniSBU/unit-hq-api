# S24-07 — Panel: write policies and the approval queue

**Repo:** `unit-hq-panel`
**Depends on:** `S24-02`, `S24-03`
**Blocks:** `S24-08`

## Goal

Two surfaces: the settings screen where an operator sets per-agent autonomy, and
the queue where they approve or reject what an agent proposed.

Panel conventions are non-negotiable and CI enforces two of them:
`ssr: false`; every string through i18n (`locales/en.json`, `es.json`,
`fr.json`); `Array<T>` never `T[]`; HTTP through `useApi()`; composables
`useXxx` / `useXxxList`; types in `app/types/`. CI is `bun run lint` +
`bun run typecheck`.

## 1. Settings → AI agents → Write policies

Path: `app/pages/settings/ai-agents.vue` (new), or a tab under the existing
settings shell — follow whatever pattern `settings/communications` uses rather
than inventing a third.

Per agent (`support`, `sales`), a table of write tools with an editable mode.

| Column | Control |
|---|---|
| Tool | label from i18n keyed by `tool_key`, not the raw key |
| Mode | select: Off / Needs approval / Automatic |
| Max per conversation | number, empty = unlimited |
| Max per day | number, empty = unlimited |
| Minimum verification | select: (tool default) / Channel asserted / Verified |

Only tools where `is_write` is true appear. The API must expose that — if
`GET /api/ai/agents` does not already return the tool list with write flags,
`S24-02` needs to add it; raise it rather than hardcoding the list in the panel.
**The tool list is code (invariant 58); the panel must not carry its own copy.**

Copy matters here more than usual. "Automatic" is the word for `commit`, and the
help text under it should say plainly what it means: *the agent will do this
without asking anyone.* An operator flipping reservations to Automatic should
understand they are handing out inventory.

`min_verification` shows the tool's own floor as the disabled default option, so
it is visible that the control raises and never lowers.

Permission: reuse `Permission::AiAgentUse` for viewing; gate editing behind
`agent_action.approve` or a new manage permission — decide with `S24-02` and do
not add an unused enum case (invariant 43: every case needs a route, policy or
role, or `PermissionCoverageTest` fails).

## 2. Pending actions queue

Path: `app/pages/leasing/agent-approvals.vue`, plus a nav entry.

Where it lives is a real question. Options:

1. Under **Leasing**, next to offers and reservations — it is leasing work, and
   the person who should approve a hold is already there. **Recommended.**
2. A tab in the Inbox — wrong: the Inbox is the *message* surface and
   invariant 57 keeps agent traces out of it.
3. Its own top-level nav item — premature for a queue that is usually empty.

Take (1).

### List

Cards, not a dense table. Each pending action shows:

- agent name and the channel it came in on;
- what it wants to do, in plain language from the `preview` — *"Hold a 10m²
  unit at Barcelona Sants for Marta Ruiz, expiring 27 Aug"*;
- for offers: the rendered price lines from `preview`, tax-inclusive display as
  the API rendered it. **The panel does no money arithmetic.** Not a sum, not a
  total, not a currency conversion. It prints what the API sent (invariant 30,
  invariant 55's spirit).
- available-units count for reservation proposals — the operator needs it;
- age and time-to-expiry, amber under 30 minutes;
- Approve / Reject, and a link into the conversation trace.

### Badge

Nav count of pending actions. Follow the Inbox badge precedent
(`GET /api/inbox/badge`, 20s poll) — a `GET /api/agent-pending-actions/badge`
returning `{ pending }`, polled on the same cadence. Do not invent a second
polling mechanism, and do not poll the full list endpoint for a count.

### Approve

`POST …/approve` can legitimately **fail** — the price moved, the unit went.
That is not an error state to hide behind a generic toast. On 422, show the
`failure_reason` inline on the card, keep the card in `pending`, and offer
Retry. This is the main UX risk in the task: a silent failure here means an
operator believes a hold exists when it does not.

On success, show what was created and link to it (offer detail / reservation
detail).

### Reject

Optional reason, free text, one field. It is written to `rejection_reason` and
is the only signal we get about *why* agents propose bad things. Encourage it in
the placeholder copy; do not make it required.

## 3. Demo chat trace pane

`app/components/demo/DemoChatTracePane.vue` already renders tool invocations with
status. Extend for the new denied reasons:

- `requires_approval` — distinct styling, not the red used for
  `verification` / `ownership`. It is not a refusal, it is a queue.
- `quota_exceeded` — show which cap was hit.
- replayed idempotent calls — mark them, so a demo does not look like a
  double-write.

When a proposal is written, the trace should link to the pending action so the
demo can show the whole loop in one screen. That is the demo's argument for the
architecture, the same way the verification toggle was S22's.

## Types

`app/types/agents.ts` gains `AgentWritePolicy`, `AgentPendingAction`,
`PendingActionStatus`, `WriteMode`. `Array<T>` throughout.

Composables: `useAgentWritePolicies` / `useAgentPendingActionList` /
`useAgentPendingAction`.

## i18n

All three locales, complete. A missing `fr` key is a CI failure and also a real
gap — French is a shipped locale for the handoff config.

Tool labels are keyed by `tool_key` under a single namespace so the settings
table has no `switch`.

## Tests

The panel's CI is lint + typecheck only, so most of this is review discipline
rather than assertions. At minimum:

- `bun run typecheck` green with `Array<T>` typing throughout.
- `PanelPermissionMirrorTest` (API-side) green after any permission mirror edit.
- Manually verify: approve-fails-422 path renders the reason inline; badge count
  matches the list; a site-scoped employee sees only their site's proposals.

## Acceptance

- [ ] Write-policy modes are editable per agent per tool, with the tool list
      sourced from the API, never hardcoded.
- [ ] "Automatic" mode carries copy that states what it means in plain words.
- [ ] The queue lives under Leasing with a polled nav badge on the Inbox cadence.
- [ ] A failed approval shows its reason inline and leaves the card actionable.
- [ ] The panel performs no money arithmetic anywhere in these screens.
- [ ] Demo trace distinguishes `requires_approval` from a refusal.
- [ ] en / es / fr complete; lint and typecheck green.
