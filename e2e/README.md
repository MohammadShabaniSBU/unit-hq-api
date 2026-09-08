# Browser-agent E2E suite

Markdown scenarios a Cursor browser agent can execute against the demo world. Host-specific setup lives in [`environment.md`](environment.md). Resolve that contract first; then follow the rules below.

## Before you start

1. Resolve `PANEL_URL`, `API_URL`, `DB_DRIVER`, and `LOGIN_PASSWORD` from [`environment.md`](environment.md).
2. Confirm the **compact** demo world is seeded. Testing **must** use `php artisan demo:seed --fresh --compact` (10 days, MAD-01 + MAD-02, cast only). Do not use a full presenter seed for this suite.
3. Read [`personas.md`](personas.md) and [`fixtures.md`](fixtures.md). You may only name cast personas and site codes from those files.
4. Open the panel at `{PANEL_URL}` in a real browser. Scenario `route` values are relative and append to `PANEL_URL`.

If a server is already responding, use it. Start one only when nothing responds.

## What to run

| Path | When |
|---|---|
| [`scenarios/_smoke/`](scenarios/_smoke/) | First pass. Six scenarios, every high-value domain once. |
| Later `scenarios/<domain>/` folders | After the smoke shape is reviewed. Not written yet. |

Skip — and label **skipped** — any scenario whose `requires_db` the resolved `DB_DRIVER` does not satisfy. `requires_db: any` always runs. `requires_db: pgsql` runs only on Postgres.

Run all `mutates: false` scenarios against one seeded database. Reset (see `environment.md`) before each `mutates: true` scenario. The ledger is append-only: you cannot un-sign a contract or un-post a charge.

## Agent rules

- Never edit application code in `unit-hq-api`, `unit-hq-panel`, or `keevaris-voice`.
- Never edit files under `scenarios/` or the support files in this folder.
- **If you encounter a bug, do not fix it.** Do not patch PHP, Vue, CSS, seeders, or the scenario. Stop the affected scenario, record the bug, and continue to the next scenario only if it does not depend on the broken one.
- Write **only** under [`e2e/runs/`](runs/) (this directory). That includes the daily results file, per-bug notes, and screenshots. Nowhere else.
- Drive the UI by visible English labels and roles. There are no `data-testid` attributes in the panel.
- Reference only cast personas and site codes by name ([`fixtures.md`](fixtures.md)). Locate anything else by filtering the UI. Crowd contacts change with `DEMO_SEED`.
- If a precondition does not hold, **stop and report**. Do not create your own fixture, do not invent a workaround, and do not mark the scenario passed.
- Screenshot on every failure. Record the path in the results file.
- Record every failure with the scenario `id`, the `route`, the `persona`, and the step number.
- Do not log in as a persona the scenario did not name.

## Results and bug reports

All reports go in `e2e/runs/` (create the date folder if needed):

| File | What |
|---|---|
| `e2e/runs/<YYYY-MM-DD>/results.md` | One file per calendar day. Pass / fail / skipped for every scenario you ran. |
| `e2e/runs/<YYYY-MM-DD>/bugs.md` | Every product bug you found. Do not put fixes here — description only. |
| `e2e/runs/<YYYY-MM-DD>/*.png` | Screenshots. Name them `{scenario-id}-step-{n}.png`. |

`results.md` entry:

```markdown
### S-01 — pass | fail | skipped
- persona: readonly
- route: /settings/people
- notes: …
- screenshot: e2e/runs/2026-09-08/S-01-step-4.png   # on fail
- bug: e2e/runs/2026-09-08/bugs.md#s-01   # if this fail is a product bug
```

`bugs.md` entry (append only):

```markdown
### S-01
- route: /settings/people
- persona: readonly
- step: 4
- what happened: …
- what was expected: …
- screenshot: e2e/runs/2026-09-08/S-01-step-4.png
```

A skip for `requires_db` is not a failure and is not a bug.

## New scenarios

Copy [`scenario-template.md`](scenario-template.md). Routes stay relative. Anchors stay in the cast/site table.
