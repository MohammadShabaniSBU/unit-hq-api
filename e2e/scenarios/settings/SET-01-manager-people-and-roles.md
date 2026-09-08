---
id: SET-01
title: The owner can open People and Roles and see seeded employees
domain: settings
route: /settings/people
persona: manager
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: []
invariants: []
---

## Preconditions

Compact demo world. `manager` holds `rbac.manage`. Positive of S-01 (`readonly` is redirected away from People).

## Steps

1. Log in as `manager` (`manager@example.com`).
2. Go to `/settings/people`. Heading **People**. Confirm there is **no** redirect to `/settings/general`.
3. Confirm the employee table includes **Ops Manager** (`ops@example.com`), **Ana López** (`agent-mad@example.com`), and **Rita Lectura** (`readonly@example.com`).
4. Go to `/settings/roles`. Heading **Roles**. Confirm system roles such as **owner**, **operations_manager**, or their display labels are listed.
5. Do not create an employee, change a grant, or archive a role.

## Expected

- People stays on `/settings/people` and lists the named employees.
- Roles loads without redirect.
- Do not invite or deactivate anyone.

## Known traps

- `ops` is redirected from People — do not use `ops` here.
- Compact has no `sm-mad-03`…`05` / `agent-sur` rows.

## On failure

Screenshot People (table) and Roles. Do not create an employee. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
