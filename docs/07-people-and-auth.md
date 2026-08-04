# People, Auth & Site Scoping

## Three human models

| Model | Who | Auth |
|---|---|---|
| `User` | Deployment superadmin | Yes |
| `Employee` | Company staff (dashboard) | Yes (Sanctum); grants via `employee_roles` |
| `Contact` | Prospect / tenant (renter) | **No login** — offer token links only |

## Employee lifecycle

Employees are created without a password. An `employee_invitations` row holds a
sha256 of a crypto-random token (raw token shown once). The invitee sets their
own password via public `GET/POST /api/invitations/{token}` (panel `/invite/:token`).
Expiry is read-time (invariant 13); no sweeper. Resend revokes the previous token.

Deactivation sets `deactivated_at`, revokes all Sanctum tokens and open invites in
the same transaction (invariant 47), and keeps grants for reactivation. The last
company-wide `owner` cannot be deactivated (`OwnerFloor`). Login folds deactivated
and null-password accounts into the same generic credential error.

Self-service: `PATCH /api/user` and `POST /api/user/password` are `Exempt::self`.

## Grant model (RBAC data)

Authorization is expressed as **role grants**, not a scalar `employees.role` column:

| Table | Purpose |
|---|---|
| `roles` | Named bundles (`owner`, `site_manager`, …); `scope_level` = `company` \| `site` \| `any`; system roles are archive-only |
| `role_permissions` | Enum values from `App\Support\Auth\Permission` (never invent permissions as rows) |
| `employee_roles` | Grant of a role to an employee; `site_id NULL` = company-wide, non-null = that site only |

`employee_roles.site_id` scopes **authorization**, not data ownership — never a global scope, middleware context, or queue payload key (same discipline as invariant 34 for `legal_entity_id`).

System roles are seeded by `RbacSystemRoleSeeder` (`is_system = true`). At least one company-wide `owner` grant must always exist (invariant 44 / `OwnerFloor`).

Permission vocabulary and grant tables are live. Resolution machinery
(`Employee::can` / `allowsPermission`, `SubjectSite`, `SystemActor`,
policies, 403 `errors.forbidden`) lives under `App\Support\Auth\` and
`app/Policies/`. **Every authenticated route** reaches a permission decision via
`Gate::authorize` / `$this->authorize` and the `RoutePermissions` manifest
(task 03). List `visibleTo` scoping (task 04) filters rows via
`App\Support\Auth\Concerns\VisibleToEmployee` + `SitePath` at each list call site.

**Release note:** the task-01 backfill grants every pre-existing employee a
company-wide `owner` role, so deployments see **no behaviour change** until an
operator narrows someone's grants. After that, narrowly-granted employees receive
403 with `{ message: "errors.forbidden", data: { permission, site_id? } }`.

Site scoping is **explicit at authorize call sites** (permission + `SubjectSite`
on the subject), never a global Eloquent scope, never middleware ambient context,
and never baked into Sanctum tokens. Company-wide grants allow anywhere; site
grants allow only when the subject's site matches.

## Site scoping in the panel (UX rule)

A **global site selector** scopes the portal context, with two carve-outs:

1. **Site-level staff** (grants with a non-null `employee_roles.site_id`) see only their assigned site(s).
2. **Company-level roles** (company-wide grants) get an **"All Sites"** option.

**Exception (D-RBAC-1):** Contact and Deal **detail** views show activity across all sites regardless of the selector — a Contact is not inherently site-scoped as a subject. **Lists** are narrower: site-scoped agents see contacts/deals related to granted sites, plus unassigned leads (no site relation / null `deals.site_id`). Company-wide grants see the full roster. Row visibility is applied via explicit `visibleTo($employee, $permission)` on the query — never a global scope.

`GET /api/user` returns `roles`, `permissions`, and `company_permissions`.

## Extensibility models

| Table | Purpose |
|---|---|
| `attribute_definitions` / `attribute_options` / `attribute_values` / `attribute_value_options` | Typed EAV custom attributes on contact, deal, offer, reservation, unit, contract. Managed in Settings → Custom attributes. Definitions are archive-only (`archived_at`) — never hard-deleted. List filter `?status=` = `active` (default), `archived`, or `all`. Archive/restore: `POST …/archive` / `POST …/unarchive` (no `DELETE`). **Advanced filters** (native + EAV): `GET /api/{entity}/filters/schema`, `POST /api/{entity}/search` with nested AND/OR JSON tree — engine in `App\Support\Filtering\` (`whereExists` per attribute condition). Schema cache invalidated on definition create/update/archive/unarchive. Saved views and column promotion remain deferred. |
| `attribute_groups` / `layout_fields` | Overview **card** layout per entity (native + custom fields). Managed in Settings → Object customization. Placement is only via `layout_fields` (FK to `attribute_groups` + either `native_field_key` or `attribute_definition_id`). `layout_fields.entity_type` is denormalized for partial unique indexes. Default cards/fields: `DefaultAttributeLayoutSeeder`. |
| `tasks` | Operator tasks (reminder channel undecided) |
| `notes` | **Append-only** notes (ERD calls them "comments"); `HasNotes` trait |

### `group_name` ≠ `AttributeGroup`

These share the word “group” but are **unrelated**:

| Concept | What it is |
|---|---|
| `attribute_definitions.group_name` | Optional **free-text** catalog label on a definition (metadata only). **Not** an FK. Does not create or select a layout card. |
| `attribute_groups` (`AttributeGroup`) | Overview **cards** in Object customization. An attribute appears on a card only when a `layout_fields` row places it. |

Do not wire the Custom attributes form `group_name` input to `AttributeGroup`. Archived definitions are excluded from the add-field picker; existing layout placements and values may remain until operators remove them.
