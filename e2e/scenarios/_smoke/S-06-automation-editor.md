---
id: S-06
title: A compiled playbook opens read-only in the automations editor and has a run timeline
domain: _smoke
route: /automations
persona: ops
mutates: false
priority: smoke
requires_db: any
depends_on: []
anchors: []
invariants: []
---

## Preconditions

Demo world seeded. Stage activation compiled **Default debt process** and **Default lead chase** into automations. Each row on `/automations` shows a **Playbook** badge.

## Steps

1. Log in as `ops`. Go to `/automations`. Heading **Automations**.
2. Find **Default debt process** (or **Default lead chase** if the debt row is missing). Confirm the **Playbook** badge.
3. Open the automation. The editor is `/automations/:id`.
4. Read the banner: **This graph is compiled from a playbook and is read-only here. Edit it on the playbook page.**
5. Confirm there is no node palette on the left, the canvas is not editable, and Save is disabled or absent.
6. Follow **Open playbook** if present — it should go to `/playbooks/:id` — then return to the automation.
7. Open the **Runs** tab (`/automations/:id/runs`). The page heading is **Execution log**. Open any run (`/automations/:id/runs/:runId`).

## Expected

- Both compiled playbooks are listed (debt + lead chase). At least one opens.
- The editor banner and missing palette prove read-only. Dragging a node or editing a node config must not persist.
- The runs list loads. If the 14-month clock produced runs, a run detail shows a step timeline (status, trigger, steps). If the list is empty, record **empty runs** and still pass the editor half — do not invent a run.
- A free-standing (non-playbook) automation is out of scope. Do not create one.

## Known traps

- The badge label is **Playbook**, not "compiled".
- Compiled graphs are edited on `/playbooks/:id`, not here. Opening the playbook is a navigation check, not a write.
- `readonly` can view automations but this smoke uses `ops`.

## On failure

Screenshot the automations table (badge) and the editor chrome (banner + missing palette). Do not save a draft graph. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
