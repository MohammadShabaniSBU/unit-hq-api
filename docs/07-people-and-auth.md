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
| `property_definitions` / `property_values` | Dynamic properties, morphable to entities |
| `tasks` | Operator tasks (reminder channel undecided) |
| `notes` | **Append-only** notes (ERD calls them "comments"); `HasNotes` trait |
