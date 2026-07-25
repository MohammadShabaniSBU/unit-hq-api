# People, Auth & Site Scoping

## Three human models

| Model | Who | Auth |
|---|---|---|
| `User` | Deployment superadmin | Yes |
| `Employee` | Manager / staff | Yes (dashboard, Sanctum) |
| `Contact` | Prospect / tenant (renter) | **No login** — offer token links only |

## Site scoping in the panel (UX rule)

A **global site selector** scopes the portal context, with two carve-outs:

1. **Site-level staff** see only their assigned site(s).
2. **Company-level roles** get an **"All Sites"** option.

**Exception:** Contact and Deal detail views show activity **across all sites regardless of the selector** — a Contact is not inherently site-scoped.

## Extensibility models

| Table | Purpose |
|---|---|
| `attribute_definitions` / `attribute_options` / `attribute_values` / `attribute_value_options` | Typed EAV custom attributes on contact, deal, offer, reservation, unit, contract. Managed in Settings → Custom attributes. Definitions are archive-only (`archived_at`) — never hard-deleted. List filter `?status=` = `active` (default), `archived`, or `all`. Archive/restore: `POST …/archive` / `POST …/unarchive` (no `DELETE`). Advance filters, saved views, and column promotion are deferred. |
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
