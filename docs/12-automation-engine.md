# Automation Engine

A named, versioned flow graph (trigger → actions/logic) that reacts to model
lifecycle events or a schedule, and walks its graph writing an append-only
execution trail. Playbooks (`13-playbooks.md`) compile down to automations —
this doc covers the graph/run engine itself. **Condition/filter syntax
(operators, typing, null rules) is documented separately in
`automation-conditions.md` — link there, don't duplicate it.**

## Model / schema

| Model | Table | Role |
|---|---|---|
| `Automation` | `automations` | Top-level definition: `name`, `description`, `status` (`AutomationStatus`: `draft`/`active`/`inactive`), `version`, `single_active_run_per_subject`, `default_guard` (condition group, snapshotted onto each run), `playbook_id` (nullable FK — set when compiled from a playbook), `archived_at` |
| `AutomationNode` | `automation_nodes` | One node in the graph: `automation_id`, `node_key` (stable string id within the graph), `kind` (`AutomationNodeKind`: `trigger`/`action`/`condition`), `type` (`AutomationNodeType`, see below), `label`, `description`, `position_x`/`position_y` (canvas layout), `config` (JSON, shape depends on `type`), `metadata` |
| `AutomationEdge` | `automation_edges` | Directed connection: `source_node_id` → `target_node_id`, `source_handle` (`default`, or `true`/`false` off a Branch), `target_handle`, `label`, `condition` (`{type: 'always'}` or `{type: 'filter', filterGroup}` — edge-level gating, distinct from Branch nodes). One edge per `(automation_id, source_node_id, source_handle)` — unique constraint; multi-target fan-out from one handle is deferred (`10-open-decisions.md`) |
| `AutomationRun` | `automation_runs` | One execution instance: `automation_id`, `trigger_node_id`, `subject_type`/`subject_id` (morph — the record that fired or is being processed; null for schedule triggers), `causer_type`/`causer_id` (morph — who/what caused the triggering write), `root_run_id`/`depth` (sub-run ancestry, capped), `status` (`AutomationRunStatus`), `trigger_payload` (immutable dispatch-time snapshot — natives + `custom_attributes` + `dirty`), `guard` (condition group snapshotted from `Automation.default_guard`), `error`, `cancel_cause`, `cancelled_by`, `waiting_until`/`current_node_id` (parked-wait cursor), `active_key` (`"{subject_type}:{subject_id}"`, non-null only while a `single_active_run_per_subject` run is in flight — backs a partial unique index), `started_at`/`completed_at` |
| `AutomationRunStep` | `automation_run_steps` | Append-only per-node execution record: `run_id`, `node_id` (nullable — null for synthetic `run.cancelled` rows), `node_type` (denormalized so history survives node deletion), `status` (`AutomationRunStepStatus`), `input`/`output`/`error` (JSON), `started_at`/`completed_at`/`duration_ms` |

Step `output` is published back into the run's in-memory `RunContext` under the
node's `node_key`, so downstream nodes can read `steps.{node_key}.*` via
`TokenResolver` — the mechanism playbook step pairing (e.g. `record_notice`
reading a prior send's `to`/`channel`) relies on.

## Node types

`AutomationNodeType` — value strings double as the handler registry key:

| Type | Kind | Purpose |
|---|---|---|
| `trigger.object_created` | trigger | Fires on model create; `config.objectType` + optional `config.filters` (snapshot-source condition group) |
| `trigger.object_updated` | trigger | Fires on model update when `config.property` is dirty; optional `config.conditions` evaluated against `{old, new}` |
| `trigger.schedule` | trigger | Fires on a cadence (`config.frequency`: `once`/`hourly`/`daily`/`weekly`/`monthly`); no subject |
| `trigger.email_received` | trigger | Modeled (type exists, `TriggerConfigValidator` accepts it) but **activation is blocked** — no inbound webhook handler wires it yet (`10-open-decisions.md`) |
| `action.update_object` | action | `UpdateObjectHandler` — resolves a target record (`TokenResolver::resolveTargetRecord`) and applies field updates (`ValueSource`: `static`/`dynamic`) |
| `action.create_object` | action | `CreateObjectHandler` — inserts a new record; allowlisted object types only (below) |
| `action.send_email` | action | `SendEmailHandler` — template (`TemplateFamily` + `TemplateResolver` locale ladder) or inline subject/body (XOR), sent via `EmailSender` |
| `action.send_sms` | action | `SendSmsHandler` — template or inline body (XOR), sent via `SmsSender` |
| `action.send_whatsapp_template` | action | `SendWhatsAppTemplateHandler` — approved-template send via `WhatsAppSender::sendResolvedTemplate`; category gate when the run belongs to a playbook (`WhatsAppPlaybookCategory`) |
| `action.record_notice` | action | `RecordNoticeHandler` — writes a `ContractNotice` (and, when the run subject is a delinquency case, a `DelinquencyStep` timeline row); can pair with a prior send step via `sent_from_node_key` to copy `sent_at`/`channel`/`to` when that send actually delivered |
| `logic.branch` | condition | `BranchHandler` — evaluates a filter group against the **trigger snapshot** (never live) and returns `handle: 'true'|'false'` for the executor to pick an outgoing edge |
| `logic.wait` | condition | `WaitHandler` — parks the run until a relative offset (`amount`/`unit`, optional `align: send_window`) or an absolute token expression (`mode: until`) |

Handlers implement `App\Support\Automation\Contracts\NodeHandler` and live
under `App\Support\Automation\NodeHandlers\`. The type→handler map is
`AutomationExecutor::HANDLERS` (also exposed via `AutomationExecutor::handlers()`
for test-harness coverage gates — every registered type must have a handler).

## Trigger dispatch pipeline

1. Models using `HasAutomationTriggers` (booted trait) fire generic
   `ModelCreated`/`ModelUpdated`/`ModelDeleted` events on their configured
   lifecycles (`automationTriggerLifecycles()`, default all three), carrying a
   frozen attribute snapshot (`automationTriggerAttributes()`) and dirty diff.
   Causer is captured at dispatch time via `Actor::current()` (queue workers
   have no request auth).
2. `MatchAutomationTriggers` (queued job) looks up candidate automation ids
   from `AutomationWatchCache` (keyed by subject type + trigger type, so most
   writes short-circuit with zero query cost), then calls
   `TriggerMatcher::match()` to pre-filter by trigger config (dirty property /
   snapshot filter group) using `ConditionEvaluator` with `ConditionSource::Snapshot`.
3. For each match, an `AutomationRun` is created `Pending` with the trigger
   payload frozen, then `ExecuteAutomationRun` is dispatched. When
   `single_active_run_per_subject` is set, `active_key` backs both a soft
   existence check and a DB unique constraint — the loser of a race is
   silently skipped (`UniqueConstraintViolationException` caught).
4. `trigger.schedule` automations don't go through this pipeline — see below.

## Loop suppression (`AutomationContext`)

`App\Support\Automation\AutomationContext` is a static, run-scoped flag (mirrors
the request-scoped `RequestId` pattern). `CreateObjectHandler` and
`UpdateObjectHandler` wrap their model writes in `AutomationContext::run($runId, …)`;
`HasAutomationTriggers::dispatchAutomationEvent` checks `AutomationContext::active()`
first and returns immediately if set. This is a **hard suppress**, not a
depth/loop-detection heuristic: automation-originated writes never re-enter the
matcher at all, so an automation editing the same object type it watches
cannot re-trigger itself. Cross-object chains (automation A's action creates a
record that automation B watches) are still allowed and go through the normal
pipeline; runaway chains there are bounded by `AutomationExecutor::MAX_DEPTH`
(5) via `run.depth`/`root_run_id`, not by `AutomationContext`.

## `action.create_object` allowlist

`CreateObjectAllowlist::TYPES = ['contact', 'deal', 'task', 'note']` — only
these four morph types may be created by the generic handler. Field values use
the same `ValueSource` shape as `update_object` (`static`/`dynamic`, not
per-field TargetRecord modes); Task/Note resolve their `taskable_*`/`notable_*`
parent via `relatedTo` (default `trigger_subject`). Contract, Reservation, and
Offer are **deliberately excluded**: creating one is not a plain insert — it
needs a real transactional path (`ContractBilling` for contracts, the offer
acceptance transaction for offers) that a generic field-mapping handler cannot
safely replicate. Dedicated nodes calling those paths are the intended future
extension, not a widened allowlist (`10-open-decisions.md`). Customer-facing
agent writes reuse the same four types (`14-ai-agents.md`); they never create
a Contract, Reservation, or Offer either.

## Run lifecycle

`AutomationRunStatus`: `pending` → `running` → (`waiting` ⇄ `running`) →
terminal (`succeeded` / `failed` / `cancelled`). All transitions funnel through
`App\Support\Automation\RunLifecycle`, which does a conditional `UPDATE …
WHERE status = :from` — the row itself is the concurrency lock, so an executor
and a canceller racing on the same run can't double-transition it.

- **`RunLifecycle::claimRunning`** — `pending`/`waiting` → `running`; loser of
  a race gets `false` and the executor bails.
- **`RunLifecycle::evaluateGuard`** — re-checked before the trigger step and
  before every node hop. `run.guard` (snapshotted `Automation.default_guard`
  at enrolment) is evaluated **live** (`ConditionSource::Live`, fresh subject
  row + live EAV) — the one place run-level evaluation reads current state
  instead of the trigger snapshot. Guard failing, or the subject having been
  deleted, cancels the run (`AutomationCancelCause::Guard` /
  `TriggerObjectDeleted`).
- **`RunLifecycle::park`** — `running` → `waiting`, storing `waiting_until` +
  `current_node_id` as the resume cursor. Triggered when a handler throws
  `Parked` (currently only `WaitHandler`).
- **`RunLifecycle::succeed` / `fail` / `cancel`** — terminal transitions;
  `cancel` also writes a synthetic `run.cancelled` step (`node_id` null) so the
  run log records cause + who cancelled it. `AutomationCancelCause`: `manual`,
  `guard`, `superseded` (playbook `exit-enrolments`), `trigger_object_deleted`.

### Wait / resume

`WaitHandler` computes a `resume_at` (relative offset, optionally aligned to
the org send-window start in site-local time; or an absolute token
expression), marks its own step `Waiting`, and throws `Parked`. The executor
catches that, parks the run, and dispatches a **delayed** `ResumeAutomationRun`
job for `resume_at`. That delayed dispatch is a latency optimization only —
the **authoritative** mechanism is `automations:resume-waiting`
(`ResumeWaitingAutomations` command), which sweeps every `waiting` run whose
`waiting_until` has elapsed and (re)dispatches `ResumeAutomationRun`; a lost
delayed job cannot strand a run. On resume, `AutomationExecutor` completes the
parked step, re-evaluates the guard, and continues walking from
`current_node_id` — branch/trigger conditions still read the **stored**
snapshot, never a re-fetch (Rule 5, `automation-conditions.md`).

### Scheduled automations

`trigger.schedule` nodes don't dispatch through the model-event pipeline.
`automations:run-scheduled` (`RunScheduledAutomations`) scans every `active`,
non-archived automation's schedule nodes each run, checks whether the node is
due (`isDue()` compares the last run's `created_at` against the configured
`frequency`), and creates a subject-less `Pending` run + dispatches
`ExecuteAutomationRun` for each due node. Both `automations:run-scheduled` and
`automations:resume-waiting` are registered `->everyMinute()` in
`bootstrap/app.php`.

## Graph walk (branching)

`AutomationExecutor::walk()` recurses edge-by-edge from a node: it looks up
all outgoing edges from the current node, picks the one whose `source_handle`
(default `'default'`) matches the handle to follow, executes that edge's
target node, and — for every **other** outgoing edge — writes a `Skipped` step
for its target (and recursively skips everything downstream of it, so a whole
untaken branch is fully accounted for in the run log, not just its first
node). A `logic.branch` node's own handler output (`handle: 'true'|'false'`)
determines which handle the walk follows next.

## API surface

All under the authenticated `api.php` group.

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/automations` | List, filterable by `search` / `status` (`draft`/`active`/`inactive`/`archived`/`all`) |
| `POST` | `/api/automations` | Create — `{name, description?, status?, nodes?, edges?}`; bulk graph replace, not incremental |
| `GET` | `/api/automations/{automation}` | Detail incl. nodes/edges |
| `PUT/PATCH` | `/api/automations/{automation}` | Update — same bulk `nodes`/`edges` replace; refused when `playbook_id` is set (compiled automations aren't hand-editable) |
| `DELETE` | `/api/automations/{automation}` | Archive (`archived_at`), not a hard delete |
| `POST` | `/api/automations/{automation}/archive` \| `/unarchive` | Explicit archive lifecycle endpoints |
| `POST` | `/api/automations/{automation}/activate` \| `/deactivate` | Activate re-validates the graph (`TriggerConfigValidator::assertAutomation`, target/create-object validators) before flipping to `active` |
| `GET` | `/api/automations/trigger-fields/{objectType}` | `TriggerableFields` schema for building trigger/condition pickers per object type |
| `GET` | `/api/automations/{automation}/runs` | Run history, filterable by `status`/`subject_type`/`subject_id`/date range |
| `GET` | `/api/automations/{automation}/runs/{run}` | Run detail incl. steps |
| `POST` | `/api/automation-runs/{run}/cancel` | Manual cancel (`AutomationCancelCause::Manual`) via `RunLifecycle::cancel` |

## Panel surface

`unit-hq-panel/app/pages/automations/` — a list page, an `[id]` flow-canvas
editor (`AutomationFlowCanvas`, built on `@vue-flow/core` with custom
Trigger/Action node components and a `useAutomationEditor` composable holding
graph state), and `[id]/runs` for run history plus a per-run step timeline.
Automations compiled from a playbook open the same canvas read-only (a banner
links back to the owning playbook) since the graph editor route is blocked
server-side for those.

## Related docs

- `automation-conditions.md` — condition/filter DSL used by trigger
  pre-filters, `logic.branch`, and run guards (this is the authoritative
  reference; don't restate operator/typing rules here)
- `13-playbooks.md` — linear playbook definitions that compile into automations
- `14-ai-agents.md` — customer-facing agent writes reuse the same four
  `CreateObjectAllowlist` types (`Contact`, `Deal`, `Task`, `Note`)
- `10-open-decisions.md` — decided/deferred items for the engine (fan-out,
  email-received activation, `create_object` allowlist scope)
