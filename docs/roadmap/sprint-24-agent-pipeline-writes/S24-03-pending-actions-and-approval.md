# S24-03 — Pending actions and operator approval

**Repo:** `unit-hq-api`
**Depends on:** `S24-02`
**Blocks:** `S24-05`, `S24-07`

## Goal

`mode = propose`: the tool computes and validates, persists a **proposal**, and
an operator clicks to commit. The commit runs the same
`App\Support\Leasing\` entry point with the employee as causer.

This is the shape invariant 54 currently forbids ("not with an operator in the
loop"). `S24-08` amends that clause. Build it correctly here so the amendment is
defensible.

## The failure mode this exists to prevent

Between propose and approve, the world moves. The catalogue price changes. The
auto-picked unit gets rented at the counter. The offer expires. **A stale
approved payload is how you rent the same unit twice.**

Therefore: the stored payload is an *intent*, never a result. Approval
re-runs the full entry point from scratch against current state and may fail.
An approval endpoint that replays a snapshot is the defect this task is designed
to avoid — reviewers should reject that implementation on sight.

## Migration — `agent_pending_actions`

| Column | Type | Notes |
|---|---|---|
| `id` | pk | |
| `agent_conversation_id` | FK, cascade | |
| `agent_tool_invocation_id` | FK, **unique** | one proposal per invocation |
| `ai_agent_id` | FK | denormalised for the queue index |
| `tool_key` | string | |
| `payload` | jsonb, not null | **server-normalised** args after schema validation — never raw model output |
| `preview` | jsonb nullable | what the operator sees: rendered price lines, unit class, expiry |
| `status` | string, not null, default `pending` | `pending` \| `approved` \| `rejected` \| `expired` \| `superseded` |
| `resolved_by_employee_id` | FK nullable | |
| `resolved_at` | timestamp nullable | |
| `rejection_reason` | text nullable | |
| `result_type` / `result_id` | morph nullable | what approval created |
| `failure_reason` | text nullable | approval ran and the entry point refused |
| `expires_at` | timestamp, not null | |
| timestamps | | |

CHECK constraints:

```sql
CHECK (status <> 'pending' OR resolved_at IS NULL)
CHECK (status NOT IN ('approved','rejected') OR resolved_by_employee_id IS NOT NULL)
CHECK (status <> 'approved' OR result_id IS NOT NULL OR failure_reason IS NOT NULL)
```

Indexes: `(status, expires_at)`, `(ai_agent_id, status, created_at)`,
`(agent_conversation_id)`.

`expires_at` defaults to `now() + config('agents.pending_action_ttl_minutes')`
(new config key, default 120). A proposal older than the reservation hold it
would create is worthless.

**`superseded`:** when the same conversation proposes the same tool again, the
prior pending row for that `(conversation, tool_key)` flips to `superseded`.
Otherwise a chatty prospect leaves an operator six near-identical cards.

## Runtime integration

`AgentWritePolicyGate` returns `denied: requires_approval` for `mode = propose`.
The dispatcher then:

1. runs the tool's **dry path** — see below;
2. writes `agent_pending_actions`;
3. returns a `ToolResult` with `status = denied`, `deniedReason = RequiresApproval`,
   and a `display` string the model can honestly relay.

The `display` wording matters and is not the model's to invent. Something in the
shape of *"I've asked a colleague to confirm that — you'll hear back shortly."*
Ship it as a translated line in `config/ai-handoff.php` alongside the canned
handoff replies, in en / es / fr. The model quotes it; `GroundingGuard` sees no
new tokens.

Crucially: the model must **not** be told the write succeeded. A proposal that
reads as a confirmation is the same defect as a false payment confirmation.

## The dry path

Each write tool implements a second method:

```php
interface ProposableTool extends AgentTool
{
    /** Validate against current state and build the payload + preview. No writes. */
    public function propose(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult;
}
```

`propose()` does everything `handle()` does except the `DB::transaction`: resolve
the site, the class, the rate, the price, the tax, the discount; confirm a unit
*would* be available. It returns `data` that becomes `payload` + `preview`.

For `sales.create_offer` that is nearly `sales.propose_offer` — reuse it rather
than writing a third pricing path.

A tool with `mode = propose` in its policy but no `ProposableTool`
implementation is a defect, not a runtime error: assert it in
`AgentToolCoverageTest`.

## API

All under `auth:sanctum`. New permission `Permission::AgentActionApprove`
(`agent_action.approve`). Register in `RoutePermissions`; mirror to panel
`app/types/permissions.ts` or `PanelPermissionMirrorTest` fails both ways.
Seed grant: `owner`, and `site_manager` — the person who should approve a hold
is the one running the site.

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/agent-pending-actions` | filters `status`, `ai_agent_id`, `tool_key`; paginated `{ meta }` |
| `GET` | `/api/agent-pending-actions/{id}` | includes conversation excerpt + invocation trace |
| `POST` | `/api/agent-pending-actions/{id}/approve` | re-validates, runs the entry point, returns the created resource |
| `POST` | `/api/agent-pending-actions/{id}/reject` | `{ reason? }` |

Site scoping: apply `visibleTo($employee, Permission::AgentActionApprove)`
explicitly at the query site. Never a global scope (invariant 46). A proposal's
site comes from `payload.site_id`; a site-scoped approver sees only their sites.

**Demo traffic is excluded by an explicit filter at each call site** (invariant
59). `origin = demo` proposals must not reach the operator queue.

### Approve semantics

Inside one transaction:

1. `lockForUpdate` the pending row; refuse unless `status = pending`.
2. refuse if `expires_at` is past — flip to `expired` and return 422.
3. re-run the tool's `propose()` against **current** state. If it now fails,
   write `failure_reason`, leave `status = pending`, return 422 with the reason.
   Do not auto-reject: the operator may want to retry in a minute.
4. call the `App\Support\Leasing\` entry point with
   `LeasingActor::employee($approver)`.
5. write `status = approved`, `resolved_by_employee_id`, `resolved_at`,
   `result_type` / `result_id`.
6. `RecordsActivity::core` on the created subject, with the agent and the
   conversation id in properties. The **employee** is the causer — they clicked.

### Click-only

Invariant 60 currently binds Copilot voice approvals. It extends here:
a pending action is resolved by an authenticated `POST` from the panel, by an
employee holding the permission. Not by a model, not by a tool, not by an
inbound message, not by a voice turn, not by an automation node. Add no
automation trigger to this table.

## Sweep command

```
php artisan agents:sweep-pending-actions
```

Flips `pending` rows past `expires_at` to `expired`. Scheduled every 10 minutes.
Mirrors `comms:sweep-uncorrelated-call-intents`. Tier-1 `SystemEvent` on non-zero
counts only.

## Tests

- `AgentPendingActionConstraintTest` — each CHECK rejects its bad shape at the
  database level.
- `PendingActionApprovalTest` — happy path creates the real row; **price changed
  since propose → 422, nothing created, row stays pending**; **unit rented since
  propose → 422**; expired proposal → 422 and flipped to `expired`; double
  approve → second one 409/422 under the lock.
- `PendingActionScopeTest` — site-scoped approver cannot see or approve another
  site's proposal; `origin = demo` never appears in the queue.
- `PendingActionSupersedeTest` — a second proposal supersedes the first.
- `ProposeModeTest` — with `mode = propose`, `handle()` is never called and the
  target table is untouched; the model receives the canned line and not a
  success message.
- `PermissionCoverageTest` / `PanelPermissionMirrorTest` green.

## Acceptance

- [ ] Approval re-validates and can fail; no code path replays a stored payload
      into the database.
- [ ] `mode = propose` writes nothing to `offers` / `reservations`.
- [ ] The model's relayed line never asserts the write succeeded, in all three
      locales.
- [ ] Demo-origin proposals never reach the queue.
- [ ] Approval stamps the **employee** as activity causer, with the agent in
      properties.
- [ ] Nothing but an authenticated panel `POST` can resolve a pending action.
