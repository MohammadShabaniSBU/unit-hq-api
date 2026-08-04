# S17-05 — Panel: permissions, people & roles

## Context

The panel currently assumes omnipotence. `10-open-decisions.md` records the stopgap plainly:
overview inline edit ignores role and site scope, always editable when
`NativeFields.editable` or a definition is present. The site selector shows every site. There
is no screen for managing who can do what, because until task 01 there was nothing to manage.

This task retires the stopgap and builds the operator-facing surface. It is also where the
sprint is most tempting to cheat: hiding a button is not authorization, and an acceptance
criterion satisfied by hiding something task 03 failed to deny is a regression disguised as
progress.

## Scope

**In:**
- `usePermissions()` composable fed by `/api/user`
- `canEdit` stopgap deleted, replaced by real permission checks
- Site selector reconciled with grants ("All Sites" only for company-wide)
- Settings → People: employee list, grant editor (role × site)
- Settings → Roles: role list + permission matrix (custom roles editable, system roles
  read-only)
- 403 handling: translated toast, no silent failure
- i18n en/es/fr for every permission, role, and new string

**Out:**
- Employee invitation / password reset flows (unchanged, whatever exists today)
- Per-role dashboards or layouts (still deferred per `10-open-decisions.md`)

## Schema changes

None (API-side; tables are task 01).

## API surface

Management endpoints land here, all gated on `Permission::RbacManage` from task 03:

```
GET    /api/employees                      # already exists; gains roles summary
GET    /api/employees/{employee}/roles
POST   /api/employees/{employee}/roles     { role_id, site_id? }
DELETE /api/employees/{employee}/roles/{grant}

GET    /api/roles                          # from task 01
POST   /api/roles                          { key, label, description?, scope_level }
PATCH  /api/roles/{role}                   { label?, description?, permissions[] }
POST   /api/roles/{role}/archive
POST   /api/roles/{role}/unarchive

GET    /api/permissions                    # enum grouped by domain
```

- System roles reject `PATCH` of `permissions` and reject archive → 422 with a translatable
  key. Their `label`/`description` are also fixed; the seeder owns them.
- Grant creation validates `scope_level` (task 01) and returns 422 on mismatch.
- Grant deletion surfaces the last-owner refusal as a 422 the panel renders inline, not a
  toast — the operator needs it attached to the row they are trying to change.
- `GET /api/user` drops the deprecated `role` string in this task. Grep the panel first.

## Panel surface

### `usePermissions()`

```ts
const { can, canAtSite, grantedSiteIds, isCompanyWide } = usePermissions()
can(Permission.ContractSign)                 // anywhere
canAtSite(Permission.ContractSign, siteId)   // specific
```

Backed by the Pinia auth store, populated from `/api/user` at login and on app boot. A
permission enum mirror lives in `app/types/permissions.ts` — generated or hand-kept in sync
with the PHP enum; if hand-kept, task 06's coverage test compares the two lists and fails on
drift, because a silently missing constant becomes a silently hidden button.

### Retiring `canEdit`

Grep the panel for `canEdit`. Every call site becomes a real check against the permission the
corresponding API endpoint requires — take the pairing from task 03's `RoutePermissions`
manifest, do not guess. Overview inline edit on a contract field checks the same permission
`PATCH /contracts/{id}` requires.

Delete the stopgap helper itself so it cannot be reintroduced, and remove the Active-WIP line
from `10-open-decisions.md`.

### Site selector

Sources from `GET /api/sites/options`, which task 04 already scopes. "All Sites" renders only
when `isCompanyWide` is true for the relevant view permission. An employee granted one site
sees that site's name as a fixed label, not a dropdown with one entry.

Preserve the documented exception: Contact and Deal detail views show cross-site activity
regardless of the selector (`07-people-and-auth.md`, refined by D-RBAC-1 in task 04).

### Settings → People

List of employees: name, email, grants summary (role chips with site names, company-wide
chips styled distinctly). Row opens a grant drawer:

- Current grants, each removable
- Add grant: role select, then site select — the site field disables and clears for
  `company` roles, is required for `site` roles, optional for `any`
- Last-owner refusal renders inline on the offending row with an explanation, not a generic
  error toast
- Empty state for an employee with no grants: explicit "This employee cannot access
  anything yet", because a blank list reads as a loading bug

### Settings → Roles

- List: label, key, scope level, system badge, permission count, archived filter
  (`?status=active|archived|all`, matching the attribute-definitions pattern)
- Detail: permission matrix grouped by `Permission::domain()`, checkbox per permission,
  domain-level select-all
- System roles render the same matrix, read-only, with a note saying they are maintained by
  the application — operators need to *see* what `accountant` means before granting it
- Creating a custom role starts from a clone of a system role, because starting from an empty
  matrix produces roles that cannot log in

### 403 handling

`useApi()` gains a 403 branch: translate `data.permission` through `permissions.*` and show
"You don't have permission to {action}". Never a raw machine key, never a silent no-op. If a
403 arrives on a control the panel rendered, that is a panel bug to fix — log it loudly in
dev.

## i18n

Every permission (`permissions.contract.sign` → "Sign contracts"), every system role
(`roles.leasing_agent`), every new string, in `en.json`, `es.json`, `fr.json`. Spanish is
first-deploy and matters most; have the client's operator review role names, since staff
titles are regional. English fallback acceptable for `fr` this sprint, key set must be
complete.

Suggested Spanish: `owner` → *Propietario*, `operations_manager` → *Responsable de
operaciones*, `site_manager` → *Responsable de centro*, `leasing_agent` → *Agente comercial*,
`accountant` → *Contabilidad*, `read_only` → *Solo lectura*.

## Invariants

- All UI strings through i18n; `Array<T>` typing; HTTP via `useApi()`; SPA only.
- **Panel hiding is not authorization.** Every hidden control has a task-03 API denial. The
  acceptance criteria below pair each UI check with an API assertion for exactly this reason.
- Archive-only family — roles archive, never hard-delete.
- `10-open-decisions.md` — the `canEdit` stopgap line is deleted, not amended.

## Acceptance criteria

- [ ] `usePermissions()` exists; the panel reads permissions from `/api/user`.
- [ ] Grep for `canEdit` in the panel returns nothing; the helper file is deleted.
- [ ] Every control hidden by a permission check has a paired API test proving 403/404 when
      called directly (list the pairs in the PR description).
- [ ] Site selector shows only granted sites; "All Sites" appears only for company-wide
      grants; a single-site employee sees a label, not a dropdown.
- [ ] Contact and Deal detail still show cross-site activity regardless of selector.
- [ ] Settings → People lists employees with grant chips; grants can be added and removed.
- [ ] Removing the last owner grant shows an inline explanation and the grant survives.
- [ ] Settings → Roles renders the permission matrix grouped by domain; system roles are
      read-only with an explanatory note.
- [ ] Creating a custom role clones a system role's matrix as its starting point.
- [ ] A 403 renders a translated message naming the action.
- [ ] `en.json`, `es.json`, `fr.json` contain every permission and role key.
- [ ] The deprecated `role` string is gone from `/api/user` and from the panel.
- [ ] `bun run lint` and `bun run typecheck` pass.

## Tests required

Panel has no test runner beyond lint/typecheck (`01-stack.md`), so verification is manual
plus the API-side pairs. Use the demo world (`demo:seed --fresh`) and the grants task 06
assigns to its personas rather than hand-building employees — a manual walk wants rich data,
which is exactly what the demo world is for now that no test depends on it.

Record this script in the PR description:

1. Log in as the demo `owner` — every nav item and action visible; no 403 in the network tab
   across a full panel walk
2. Log in as the demo site manager — only their site in the selector, no "All Sites"
3. Same employee: units, contracts, invoices and boards show only their site; board counts
   match table totals
4. Same employee: open a contact who rents at two sites → detail shows both sites' activity
5. Log in as the demo leasing agent — no billing run button, no settings section; call
   `POST /api/billing-runs` directly with their token → 403
6. Log in as the demo accountant — can issue an invoice, cannot see contract sign action;
   call `POST /api/contracts` directly → 403
7. As owner, remove the agent's site grant and add another site → the agent's next page load
   reflects it with no logout
8. As owner, attempt to remove your own last owner grant → inline refusal, grant intact
9. Create a custom role cloned from `leasing_agent`, remove one permission, grant it, verify
   the corresponding control disappears **and** the endpoint 403s
10. Switch locale to `es` → every role and permission label is translated
