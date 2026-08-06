# S18-08 — Doc realignment: insights and invariants

## Context

Six sprints from now a fresh Cursor session will be asked to "add a report". If the docs still
describe Insights as three hardcoded pages, it will write a fourth hardcoded page, and the
registry will rot into a thing only one person understands.

This task closes the sprint by making the docs describe what now exists.

## Scope

**In:**
- New `11-insights.md` domain doc
- Invariants 29–32 appended to `09-conventions-and-invariants.md`
- Invariant 5 clarified for `analytics` materialized views
- `AGENTS.md` routing row
- `10-open-decisions.md` updated: I1–I6 moved to Decided, new Undecided entries recorded
- `00-overview.md` doc-set index and product scope table updated

**Out:**
- Any code

## Implementation notes

### `11-insights.md`

Structure it like `06-communications.md`, because it is the same shape:

- The registry: one table, two sources, operator-ordered
- `analytics_accounts` and the credential rules (pointing at `06` rather than restating)
- Capability-by-interface table for the four adapter interfaces
- Params: static/dynamic, locked/default, the whitelist, and **why** dynamic is always locked
- The embed flow, end to end, in seven numbered steps
- The `analytics` read schema as a contract, with the invariant-to-trap table from task 01
- What deliberately stays native: anything fiscal, anything a tenant receives
- Table index

The "what stays native" section is the one most likely to be ignored, so make it a short
numbered list with reasons, not prose:

1. **Fiscal documents** — Verifactu registros, invoice books, AEAT submissions, SDD run and
   returns reconciliation. A gestor asking where a number came from must get a `registro`, not
   a dashboard someone edited last Tuesday.
2. **Tenant-facing figures** — Statements are computed views in the app. A BI export must
   never become the document a customer receives.
3. **Operational reads inside transactional screens** — contract balance banners, the unit
   class occupancy matrix, `?available=1`. These are permission-scoped and sub-100ms; an
   iframe is the wrong tool and the wrong latency.

### Invariants to append to `09`

> **29. Analytics provider credentials follow the shared credential rules.** Invariants 26 and
> 27 apply unchanged to `analytics_accounts` — encrypted at rest, masked last-4 in responses,
> blank submitted field means unchanged, Tier-3 `RecordsActivity::core` on create / rotate /
> remove, `DecryptException` degrades to `credentials_unreadable`. Shared helpers in
> `App\Support\Credentials\`.

> **30. A dynamic insight param is always locked.** `value_source = 'dynamic'` implies
> `binding = 'locked'`, enforced by a database `CHECK`. Only static params may be editable
> defaults. Dynamic values resolve from the `App\Support\Insights\DynamicParams` whitelist —
> never from a client-supplied key, never from an expression language.

> **31. Embed tokens are minted server-side and short-lived.** The provider's signing secret
> never appears in an API response, a queue payload, an activity log, a system event, an
> exception message, or the panel bundle. The panel receives a URL and renders it; it never
> constructs one. TTL ≤ 10 minutes.

> **32. Insight reports and analytics accounts are archive-only.** `archived_at`,
> `POST …/archive` / `…/unarchive`, `DELETE` aliases archive. Built-in (`is_system`) reports
> can be hidden and relabelled but never archived or repointed.

### Clarify invariant 5

It already carries the S01-01 clarification distinguishing fact tables from cached derived
state. Add the second one:

> Exception: views and materialized views in the `analytics` schema are a **reporting
> projection** of facts, refreshed on a schedule and read only by external analytics tools.
> No application code queries `analytics`. This is not cached derived state on a business
> table.

Also note the third stored-cache exception introduced this sprint —
`insight_reports.validation_status` caches an **external** system's state, not ours.

### `AGENTS.md`

Add a routing row:

| Working on… | Read |
|---|---|
| Insights, embedded analytics, reporting schema | `11-insights.md` |

And a non-negotiable line: *Embed tokens are minted server-side only; dynamic params are always
locked.*

### `10-open-decisions.md`

Move I1–I6 and the OSS/paid commercial answer into **Decided (do not reopen)** with rationale.
Record these as **Undecided**:

| Topic | Options / notes |
|---|---|
| Report-level permissions | `visibility` enum ships as a stub; only `all` is honoured. Real enforcement lands with RBAC (S17) through the same helper as `canEdit` |
| Currency conversion in reporting | `analytics` views expose `currency` and never sum across it. A conversion layer needs an FX rate source and a policy on which date's rate applies — not started |
| Additional `analytics` views | Demand-driven. Five ship this sprint; there is no target catalogue |
| Scheduled report delivery | Metabase has subscriptions; do we surface them, or build our own on the comms stack? Duplicating delivery is how two unsubscribe lists appear |
| Per-report caching | Provider-side caching only for now |

Add to **Explicitly out of scope**:

- Writing to a customer's analytics instance (publishing dashboards, flipping embed params)
- A generic expression language for dynamic params
- Cross-report filtering, favourites, dashboards-of-dashboards
- Tenant-facing analytics of any kind (contacts still do not log in)

### `00-overview.md`

Add Insights to the product scope table, and `11-insights.md` to the doc-set index.

## API surface

None.

## Panel surface

None.

## Invariants

This task is where invariants 29–32 become real. Until it lands, tasks 02–07 quote drafts.

## Acceptance criteria

- [ ] `11-insights.md` exists and covers all nine sections above.
- [ ] `09-conventions-and-invariants.md` contains invariants 29–32 verbatim.
- [ ] Invariant 5 carries the `analytics` schema clarification and the external-cache note.
- [ ] `AGENTS.md` has the routing row and the non-negotiable line.
- [ ] I1–I6 are in **Decided**; the five new Undecided rows and four out-of-scope items exist.
- [ ] `00-overview.md` scope table and doc index updated.
- [ ] No doc still describes Insights as a fixed set of pages — grep the doc set.
- [ ] **A fresh Cursor session, given only the doc set, answers "how do I add a report?" with
      "add a registry entry and re-run the seeder, or define one in Settings" — not "write a
      new Nuxt page."** This is the actual exit criterion; the rest is bookkeeping.

## Tests required

None — documentation only. Verified by the last acceptance criterion, run as an actual
experiment against a clean session before the sprint is closed.
