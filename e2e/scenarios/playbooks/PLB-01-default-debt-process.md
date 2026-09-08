---
id: PLB-01
title: Default debt process is listed active with visible steps
domain: playbooks
route: /playbooks/debt-process
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: []
invariants: []
---

## Preconditions

Compact demo world. Stage activated and compiled **Default debt process**. Hit the linear playbook page, not `/automations/:id` (S-06).

## Steps

1. Log in as `ops`. Go to `/playbooks/debt-process`. Heading **Debt process**.
2. Find **Default debt process** in the table. Confirm status **Active** and a non-zero **Steps** count.
3. Open the row (`/playbooks/:id`). Confirm the heading is **Default debt process** and the **Active** badge.
4. Stay on **Builder**. Confirm the timed steps are listed (send / task / notice — do not add or send any).
5. Do not click New playbook. Do not archive.

## Expected

- The kind list shows **Default debt process**, active, with steps.
- The builder shows the linear step list. Do not open the compiled graph editor.
- Do not send email/SMS/WhatsApp from a step.

## Known traps

- Nav label is **Debt process**. Do not use `/automations/1` as a substitute.
- Creating a playbook would mutate — out of scope.

## On failure

Screenshot the debt-process list and the builder steps. Do not create or send. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
