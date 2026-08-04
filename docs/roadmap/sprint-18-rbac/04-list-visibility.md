# S17-04 — List visibility & site scoping

## Context

Task 03 decides whether an employee may *reach* an endpoint. This task decides which rows
come back. They are different questions and conflating them produces the worst version of
both: a site-scoped leasing agent who gets a 403 on `GET /contracts` (useless) or one who
gets every site's tenancy roster (a leak).

This is also the task that most resembles the thing the invariants forbid. Multi-tenancy
scopes every query from ambient context to isolate customers. What this task does is filter
lists by explicit grant at the call site, with the scoping visible in the query builder
chain, no global scope, no middleware-set current site, no queue context. If a reviewer
cannot see `->visibleTo($employee)` in the code, it is wrong — the same discipline invariant
34 imposes on `legal_entity_id`.

## Scope

**In:**
- `VisibleToEmployee` trait / scope convention across site-bearing models
- Every list, board, matrix, options and search endpoint scoped
- Site selector reconciled with grants (`/api/sites/options` returns only granted sites)
- Advanced filters (`POST /api/{entity}/search`) scoped without breaking `FilterableFields`
- Reports namespace scoped
- 404-vs-403 consistency for out-of-scope records
- D-RBAC-1: the Contact visibility ruling

**Out:**
- Panel rendering of the site selector (task 05)
- Saved views / column promotion (still deferred, unchanged)

## Schema changes

None. If query plans degrade on the 500-unit / demo-world dataset, add covering indexes on
`employee_roles (employee_id, site_id)` — already present from task 01 — and confirm before
inventing more.

## Implementation notes

### The primitive

```php
// App\Support\Auth\Concerns\VisibleToEmployee  (trait on the model)
public function scopeVisibleTo(Builder $q, Employee $employee, Permission $permission): Builder
```

Behaviour:

- Employee holds the permission **company-wide** → no filter applied. Return `$q` untouched.
  This is the common case and must cost nothing.
- Employee holds it at specific sites → constrain to those site ids via the model's site
  path.
- Employee holds it nowhere → `whereRaw('1 = 0')`. Not an exception: task 03 already
  answered "may they reach this endpoint", and an empty list is the honest answer for
  someone who can reach it but is granted no sites.

Each model declares its site path once — reuse the same relationships `SubjectSite` uses so
the two cannot drift. `Contract` scopes through `unit_occupancies`; `Invoice` through
contract; `MessageThread` through the sender identity. Where `SubjectSite` walks one row,
the scope walks a `whereExists` — no joins that multiply rows, no N+1, same posture as the
S01 availability query.

### D-RBAC-1 — Contacts

`07-people-and-auth.md` states a Contact is not site-scoped and its detail view shows
activity across all sites. That rule stands, but "not site-scoped" cannot mean "every agent
sees the full customer roster of every site" once grants are real.

**Ruling:** the contact **list** shows contacts having at least one relation (deal,
reservation, contract, or message thread) to a granted site, **plus** contacts with no site
relation at all — unassigned leads, which any agent may pick up. Once a contact is visible,
the **detail** view is unchanged: full cross-site activity, exactly as documented, because a
contact who rents at two sites is one person and hiding half their history produces wrong
operator decisions.

Record this in `10-open-decisions.md` as D-RBAC-1 with that rationale. Same rule applies to
Deals, which inherit the exception in the same doc.

### Surfaces to scope

Walk them; do not sample. Grep for `::query()`, `->paginate(`, `all()` in controllers.

- **Lists** — contacts, deals, offers, reservations, contracts, units, invoices, payments,
  overdue, delinquencies, notices, automations (company-level, unscoped), playbooks
  (company-level), access points/events, e-sign envelopes, tasks, activities.
- **Boards** — the six `*BoardController`s. Their **counts must match** the scoped list, or
  the tab says 40 and the table shows 12.
- **Matrices** — the Unit Class occupancy matrix and the Rates matrix are site×class grids.
  A site-scoped employee gets fewer *columns*, and the single-grouped-query requirement from
  S01-03 still holds.
- **Options endpoints** — `sites/options` is the site selector's source and must return only
  granted sites. `units/options`, `unit-classes/options`, `insurances/options` scope
  accordingly. `countries/options` and `tax-rates/options` are company-level, unscoped.
- **Advanced filters** — `POST /api/{entity}/search`. Apply `visibleTo` to the base builder
  **before** `FilterBuilder` runs, so a crafted filter tree cannot widen the set. Never add
  `site_id` to `FilterableFields` as a client-supplied column for this purpose; the scope is
  server-side and non-negotiable.
- **Reports** — `Support/Reports/`. RentRoll, Occupancy, Ageing, Collections,
  DepositLiability, DailyClose, Movement, Funnel, Dashboard. Each takes the employee and
  scopes its base set. `ReportFinancialView` gates the money-bearing ones at the task-03
  layer; this task ensures a site manager's Ageing report reconciles to *their* board chip,
  preserving the S16 cross-surface consistency property.
- **Inbox** — threads scope by the site identity that owns them. The S11 aggregate API's
  lateral preview and cursor pagination must scope in the same query, not post-filter, or
  cursors skip pages.

### 404 vs 403

For a site-bearing record outside an employee's granted sites, return **404**, not 403 — a
403 confirms the record exists, and combined with sequential ids that enumerates the estate.
Task 03's policies naturally produce 403; add route-model-binding scoping so out-of-scope
records never reach the policy. Where an employee holds the permission at *some* site, the
distinction matters; where they hold it nowhere, 403 is correct and already happens.

Be consistent: if `GET /contracts/{id}` 404s for an out-of-scope contract, then
`POST /contracts/{id}/vacate` must 404 too, not 403.

### Performance

Add a query-count assertion to the heaviest scoped endpoints, as S01-03 did for
availability. Company-wide employees must see **zero** added queries versus today — the
no-filter fast path is what keeps the common case free.

## API surface

No new endpoints. `GET /api/sites/options` returns a subset. Every list endpoint returns a
subset for site-scoped employees. Pagination `meta` reflects scoped totals.

## Panel surface

None here; task 05 renders it. But note for that task: the site selector's "All Sites"
option appears only for employees with a company-wide grant, per `07-people-and-auth.md`.

## Invariants

- Invariant 1 / 34 — scoping is explicit at call sites. **No global scopes.** A
  `booted()`-registered global scope on any model in this task is a defect.
- Advanced-filter convention — native fields come from the `FilterableFields` whitelist;
  visibility is applied server-side before the tree, never as a client-expressible condition.
- `07-people-and-auth.md` Contact/Deal exception — preserved for detail views, refined for
  lists by D-RBAC-1.
- S16 cross-surface consistency — a scoped report must still reconcile to the scoped board
  chip and the scoped list.
- New invariant (next free number):

> **Authorization scoping is explicit and local.** Row visibility is applied via an explicit
> `visibleTo()` call at the query site. It must never be a global scope, middleware-set
> context, or queue payload key. A company-wide grant applies no filter at all.

## Acceptance criteria

- [x] Every list, board, matrix, options and search endpoint applies `visibleTo`.
- [x] Board counts equal the scoped list totals on every board.
- [x] `GET /api/sites/options` returns only granted sites; company-wide grants return all.
- [x] A site-scoped employee's `POST /api/contracts/search` cannot return an out-of-scope
      contract regardless of filter tree.
- [x] Unit Class matrix and Rates matrix render scoped columns, still one grouped query.
- [x] A site-scoped employee's Ageing report reconciles exactly to their delinquency board
      chip (S16 property preserved under scoping).
- [x] Inbox threads scope inside the aggregate query; cursor pagination skips no pages.
- [x] Out-of-scope record by id returns 404 on both read and action endpoints.
- [x] A company-wide employee executes the same number of queries as before this task
      (assert on two heavy endpoints).
- [x] No global scope was added anywhere — grep for `addGlobalScope` returns pre-existing
      uses only.
- [x] D-RBAC-1 recorded in `10-open-decisions.md`; `07-people-and-auth.md` updated.

## Tests required

| Test | Asserts |
|---|---|
| `VisibilityTest::company_grant_applies_no_filter` | Fast path, query count unchanged |
| `VisibilityTest::site_grant_filters_contracts` | Only granted sites' rows |
| `VisibilityTest::no_grant_returns_empty_not_error` | `1=0`, 200 with empty data |
| `VisibilityTest::board_counts_match_scoped_list` | Per board, table-driven |
| `VisibilityTest::sites_options_returns_only_granted` | Site selector source |
| `VisibilityTest::search_tree_cannot_widen_scope` | Crafted filter tree defeated |
| `VisibilityTest::out_of_scope_record_is_404_on_read_and_action` | Enumeration defence |
| `ContactVisibilityTest::lists_contacts_related_to_granted_sites` | D-RBAC-1 |
| `ContactVisibilityTest::lists_unassigned_contacts` | D-RBAC-1 leads clause |
| `ContactVisibilityTest::detail_still_shows_all_site_activity` | Documented exception intact |
| `ReportScopeTest::ageing_reconciles_to_scoped_board` | S16 consistency under scoping |
| `ReportScopeTest::rent_roll_scoped_totals` | Money honesty under scoping |
| `InboxScopeTest::threads_scoped_in_aggregate_query` | No post-filter, cursor integrity |
| `VisibilityPerformanceTest::scoped_list_has_no_n_plus_one` | Query-count bound |
