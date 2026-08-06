# Playbooks

A **linear**, kind-typed enrolment sequence (offset-days steps of send/notify
actions) that operators author without touching the graph editor. Playbooks
are not a separate execution engine — a playbook **compiles into an
`Automation`** and runs entirely through the engine documented in
`12-automation-engine.md`. This doc covers the playbook-specific layer: the
linear model, compilation, kind rules, and lifecycle. Read `12` first for
runs/nodes/edges/lifecycle vocabulary used throughout.

## Model / schema

| Model | Table | Fields |
|---|---|---|
| `Playbook` | `playbooks` | `kind` (`PlaybookKind`), `name`, `is_active`, `enrolment_filters` (JSON, kind-specific whitelist), `automation_id` (nullable FK → the currently-compiled `Automation`), `archived_at` |
| `PlaybookStep` | `playbook_steps` | `playbook_id`, `offset_days` (int, non-decreasing across sort order), `action` (`PlaybookStepAction`), `params` (JSON, action-specific), `sort` |

`Playbook::steps()` is ordered by `sort`. `Playbook::automations()` is the full
history of every `Automation` ever compiled for this playbook (one row per
`activate`/edit-triggered recompile); `Playbook::automation()` points at the
current one.

## Kinds

`PlaybookKind` enum: `debt_process` | `lead_chase`. Each kind is a small
strategy object implementing `App\Support\Playbooks\PlaybookKind`
(`App\Support\Playbooks\Kinds\DebtProcess` / `LeadChase`), resolved by
`PlaybookKindRegistry::for()`:

| Method | Role |
|---|---|
| `trigger(filters)` | Returns the compiled trigger node's `{type, label, config}` |
| `guard(filters)` | Returns the run-guard condition group (`Automation.default_guard` — must keep holding for the run to continue; evaluated live on every node hop, see `12`) |
| `allowedActions()` | Whitelist of `PlaybookStepAction` values this kind's steps may use |
| `validateFilters(filters)` | Rejects unknown `enrolment_filters` keys / wrong types |
| `subjectDescriptor()` | `'delinquency'` or `'deal'` — vocabulary + panel linking |

| Kind | Trigger | Guard (must keep holding) | Filters | Allowed step actions |
|---|---|---|---|---|
| `debt_process` | `trigger.object_created` on `delinquency`, optional `site_id in`/`delinquency_policy_id in`/`days_overdue gte` from `site_ids`/`policy_ids`/`min_days_overdue` | `cured_on is_empty` | `site_ids`, `policy_ids`, `min_days_overdue` | send_email, send_sms, send_whatsapp_template, create_task, record_notice |
| `lead_chase` | `trigger.object_created` on `deal`, unfiltered | `status not_in [closed_won, closed_lost]`, optionally narrowed to `status in {stages}` | `site_ids`, `stages` | send_email, send_sms, send_whatsapp_template, create_task (**no** `record_notice` — that action is contract/delinquency-specific) |

`PlaybookStepAction`: `send_email`, `send_sms`, `send_whatsapp_template`,
`create_task`, `record_notice`.

## `enrolment_filters` → trigger conditions

Filters are never a free-text expression — each kind hand-maps its known
filter keys onto a fixed set of trigger condition fields (`trigger()` above).
`validateFilters()` rejects anything outside that whitelist before compile, so
an operator-editable filter panel can never smuggle an arbitrary field/operator
into the trigger config.

## Compilation (`PlaybookCompiler`)

`PlaybookCompiler::compile($playbook)` turns the linear step list into a graph
and writes it as a **new `Automation`**:

1. Resolve the kind, validate `enrolment_filters`, and assert every step's
   `action` is in `kind->allowedActions()`.
2. Build nodes/edges: one trigger node, then for each step — if its
   `offset_days` advances past the previous step's, insert a `logic.wait`
   node (`amount` = the delta in days, `align: send_window`) before the
   action node. Step offsets must be **non-decreasing** in sort order or
   compile fails.
3. **Pairing sugar**: a `send_email`/`send_sms` step with
   `params.record_notice = "<notice_type>"` gets an extra `action.record_notice`
   node chained after it, wired via `sent_from_node_key` so the notice
   inherits the send's `to`/`channel`/`sent_at` only if that send actually
   delivered (a skipped `no_channel` send still records an *unsent* notice —
   the attempted-notify is itself the audit fact; see `RecordNoticeHandler`
   in `12`).
4. `create_task` steps compile to `action.create_object` (`objectType: task`,
   `relatedTo: trigger_subject`) — i.e. playbooks reuse the same generic
   handler and allowlist as the graph editor, not a separate task-creation
   path.
5. Nodes/edges run through the same validators the graph editor uses
   (`TargetRecordValidator`, `CreateObjectValidator`, `TriggerConfigValidator`)
   before anything is persisted.
6. In one transaction: the playbook's **previous** compiled automation (if
   any) is marked `inactive`, a new `Automation` is created
   (`single_active_run_per_subject: true`, `default_guard` = the kind's guard,
   `playbook_id` = this playbook, `status` = `active` iff `playbook.is_active`),
   nodes/edges are bulk-inserted, and `playbook.automation_id` is repointed at
   it. `AutomationWatchCache::flushAll()` runs after commit.

Every edit that changes graph-affecting content (`name`, `enrolment_filters`,
or `steps`) triggers a fresh compile — **each compile is a new `Automation`
row**, not an in-place graph edit. This is why enrolment history spans
multiple automation versions (see lineage below) and why a compiled
automation is not directly editable in the flow-canvas editor (`12`).

## Debt playbook overlap (`DebtPlaybookOverlap`)

v1 routing rule: **at most one active `debt_process` playbook per
site-coverage set.** `siteCoverage(filters)` returns `null` (meaning "all
sites") when `site_ids` is empty/absent, else the explicit id list.
`coversOverlap($a, $b)` treats either side being `null` as an overlap (an
all-sites playbook conflicts with everything), otherwise it's a plain
intersection check. `DebtPlaybookOverlap::assertCanActivate()` is called on
`POST /playbooks/{playbook}/activate` and rejects activation with a 422 naming
the conflicting playbook if any other active debt playbook's coverage
overlaps. `policy_ids` / `min_days_overdue` do **not** factor into exclusivity
— only site coverage does. Richer priority/routing across overlapping site
sets is deferred (`10-open-decisions.md`); `lead_chase` has no equivalent
restriction.

## Lifecycle

| Action | Endpoint | Effect |
|---|---|---|
| Create | `POST /api/playbooks` | Creates the playbook (`is_active: false`) and immediately compiles it (steps included in the same request) |
| Update | `PUT/PATCH /api/playbooks/{playbook}` | Updates `name`/`enrolment_filters`/`steps`; recompiles whenever any of those changed. Refused once `archived_at` is set |
| Activate | `POST /api/playbooks/{playbook}/activate` | `DebtPlaybookOverlap` check (debt kind only) → `is_active = true` → compile if never compiled → set the compiled `Automation.status = active` |
| Deactivate | `POST /api/playbooks/{playbook}/deactivate` | `is_active = false`, compiled `Automation.status = inactive`. New enrolments stop; in-flight runs are **not** touched |
| Exit enrolments | `POST /api/playbooks/{playbook}/exit-enrolments` | Cancels every `pending`/`running`/`waiting` run across **all** automation versions this playbook has ever compiled, cause `superseded` (`RunLifecycle::cancel`) |
| Archive | `DELETE /api/playbooks/{playbook}` | Soft-archives (`archived_at`), forces `is_active = false`, and deactivates the compiled automation |
| List enrolments | `GET /api/playbooks/{playbook}/enrolments` | Paginated `AutomationRun`s across the playbook's full compiled-automation lineage, filterable `status=active\|exited` |
| List / show | `GET /api/playbooks`, `GET /api/playbooks/{playbook}` | List (filter `kind`/`search`); show includes a live `active_enrolment_count` |

Both create and update require `Permission::PlaybookManage`.

## Enrolment summary (`PlaybookEnrolmentSummary`)

Because each compile produces a new `Automation`, "this playbook's runs" is
never a single `automation_id` — it's every run whose automation has this
`playbook_id` (`lineageQuery()`). Helpers:

- `activeStatuses()` / `exitedStatuses()` — the `pending`/`running`/`waiting`
  vs. `succeeded`/`failed`/`cancelled` split used by the enrolments filter.
- `activeForSubject($type, $id)` — the single active enrolment (if any) for a
  given subject, used to surface playbook progress inline on a delinquency
  case or deal.
- `progress($run)` — completed vs. total **action** nodes (condition/trigger
  nodes don't count), used to render step-progress dots.
- `loadSubjects()` / `subjectPayload()` — batch-loads and shapes the
  delinquency/deal + contact/contract context for a page of enrolment rows
  (avoids N+1 across `subject_type`).

## API surface

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/playbooks` | List, filter `kind`/`search` |
| `POST` | `/api/playbooks` | Create + compile |
| `GET` | `/api/playbooks/{playbook}` | Detail incl. steps + `active_enrolment_count` |
| `PUT/PATCH` | `/api/playbooks/{playbook}` | Update + recompile |
| `DELETE` | `/api/playbooks/{playbook}` | Archive |
| `POST` | `/api/playbooks/{playbook}/activate` \| `/deactivate` | Lifecycle |
| `POST` | `/api/playbooks/{playbook}/exit-enrolments` | Cancel in-flight runs across the full lineage |
| `GET` | `/api/playbooks/{playbook}/enrolments` | Paginated run history, `status=active\|exited` |

## Panel surface

`unit-hq-panel/app/pages/playbooks/` — `debt-process.vue` and `lead-chase.vue`
are kind-specific list/landing pages, `[id].vue` is the shared detail/edit
view. Components under `app/components/playbooks/`: `PlaybookBuilder.vue`
(step editor — offset days, action, params, including template/token pickers
per action type), `PlaybookEnrolments.vue` (active/exited enrolment list,
subject links, progress), `PlaybookKindList.vue`, `ProgressDots.vue` (renders
`PlaybookEnrolmentSummary::progress()` output), and `TokenInsertMenu.vue`
(inserts trigger/step-output tokens into email/SMS/WhatsApp step content —
the same token grammar `TokenResolver` resolves at send time).

## Related docs

- `12-automation-engine.md` — the graph/run engine playbooks compile into;
  node types, run lifecycle, wait/resume, loop suppression
- `automation-conditions.md` — condition DSL used by kind `trigger()`/`guard()`
- `06-communications.md` — `SendContext` provenance (`playbook` source),
  WhatsApp template category gating, notice/notification plumbing
- `10-open-decisions.md` — debt playbook routing, payment-link follow-up
