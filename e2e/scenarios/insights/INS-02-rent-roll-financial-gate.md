---
id: INS-02
title: An accountant can open rent roll; a leasing agent cannot
domain: insights
route: /insights/rent-roll
persona: accountant
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: []
invariants: []
---

## Preconditions

Compact demo world. **Rent roll** is a native financial report (`report.financial.view`). `accountant` has it; `agent-mad` does not.

## Steps

1. Log in as `accountant`. Go to `/insights/rent-roll` (paste the path).
2. Confirm a native **Rent roll** shell renders (heading / table). Do not export as a required write.
3. Sign out.
4. Log in as `agent-mad`. Paste `/insights/rent-roll` again.
5. Confirm the agent is redirected or denied (error, empty gate, or away from rent-roll). A full financial table for the agent is a fail.

## Expected

- Accountant sees the native rent-roll page.
- Agent-mad does not see the financial report data.
- Native only — no Metabase.

## Known traps

- Use `agent-mad` for the negative, not `ops` (ops may hold `report.view` / financial).
- Do not treat a missing analytics account as the denial — the native page must itself gate.

## On failure

Screenshot the accountant rent-roll and the agent-mad result (URL + heading). Do not connect an embed. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
