# Browser-agent E2E suite

Markdown scenarios a Cursor browser agent can execute against the demo world. Host-specific setup lives in [`environment.md`](environment.md). Resolve that contract first; then follow the rules below.

## Before you start

1. Resolve `PANEL_URL`, `API_URL`, `DB_DRIVER`, and `LOGIN_PASSWORD` from [`environment.md`](environment.md).
2. Confirm the **compact** demo world is seeded. Testing **must** use `php artisan demo:seed --fresh --compact` (10 days, MAD-01 + MAD-02, cast only). Do not use a full presenter seed for this suite.
3. Read [`personas.md`](personas.md) and [`fixtures.md`](fixtures.md). You may only name cast personas and site codes from those files.
4. Create a new run folder `e2e/runs/<YYYY-MM-DD-HHmmss>/` using the host local clock at first write. Do not append to an older folder.
5. Open the panel at `{PANEL_URL}` in a real browser. Scenario `route` values are relative and append to `PANEL_URL`.

If a server is already responding, use it. Start one only when nothing responds.

## What to run

| Path | When |
|---|---|
| [`scenarios/_smoke/`](scenarios/_smoke/) | First pass. Six scenarios, every high-value domain once. |
| [`scenarios/leasing/`](scenarios/leasing/), [`scenarios/facility/`](scenarios/facility/), [`scenarios/billing/`](scenarios/billing/), [`scenarios/playbooks/`](scenarios/playbooks/), [`scenarios/insights/`](scenarios/insights/), [`scenarios/settings/`](scenarios/settings/), [`scenarios/inbox/`](scenarios/inbox/), [`scenarios/marketing/`](scenarios/marketing/) | Core pass. Run after smoke. |

Skip — and label **skipped** — any scenario whose `requires_db` the resolved `DB_DRIVER` does not satisfy. `requires_db: any` always runs. `requires_db: pgsql` runs only on Postgres.

Run all `mutates: false` scenarios against one seeded database. Reset (see `environment.md`) before each `mutates: true` scenario. The ledger is append-only: you cannot un-sign a contract or un-post a charge.

## Agent rules

- Never edit application code in `unit-hq-api`, `unit-hq-panel`, or `keevaris-voice`.
- Never edit files under `scenarios/` or the support files in this folder.
- **If you encounter a bug, do not fix it.** Do not patch PHP, Vue, CSS, seeders, or the scenario. Stop the affected scenario, record the bug, and continue to the next scenario only if it does not depend on the broken one.
- Write **only** under [`e2e/runs/`](runs/) (this directory). That includes the session results file, per-bug notes, and screenshots. Nowhere else. Create `e2e/runs/<YYYY-MM-DD-HHmmss>/` at the start of the run.
- Drive the UI by visible English labels and roles. There are no `data-testid` attributes in the panel.
- Reference only cast personas and site codes by name ([`fixtures.md`](fixtures.md)). Locate anything else by filtering the UI. Crowd contacts change with `DEMO_SEED`.
- If a precondition does not hold, **stop and report**. Do not create your own fixture, do not invent a workaround, and do not mark the scenario passed.
- Screenshot on every failure. Record the path in the results file.
- Record every failure with the scenario `id`, the `route`, the `persona`, and the step number.
- Do not log in as a persona the scenario did not name.
- **Never send.** Do not click Send, Reply, Resend, Submit template, or any composer confirm on inbox, offers, playbooks, or marketing. Read already-seeded threads and lists only. Provider HTTP fakes run at seed time; the live API will call real Brevo / Twilio / Sinch if you send. S-05 may **Discard** a triage stranger (local DB write) — that is not a provider send.
- **No live AI.** Skip Copilot, `/leasing/voice-sessions`, `/leasing/agent-approvals`, `/demo/chat`, `/settings/ai-agents`, `/settings/ai-providers`, and any Generate AI summary control. `/demo/chat` is a live concierge path, not a mock. Agent mock coverage is `php artisan agent:replay` (PHPUnit), not this suite.
- **No live payments or analytics accounts.** Native Insights pages only. Do not require a Metabase embed, Stripe Dashboard, or Signable live call.

## Results and bug reports

All reports go in `e2e/runs/` (create the timestamped session folder at first write):

| File | What |
|---|---|
| `e2e/runs/<YYYY-MM-DD-HHmmss>/results.md` | One file per agent session. Pass / fail / skipped for every scenario you ran. |
| `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md` | Every product bug you found. Do not put fixes here — description only. |
| `e2e/runs/<YYYY-MM-DD-HHmmss>/*.png` | Screenshots. Name them `{scenario-id}-step-{n}.png`. |

Do not append to an older folder. The existing `e2e/runs/2026-09-08/` tree is historical.

`results.md` entry:

```markdown
### S-01 — pass | fail | skipped
- persona: readonly
- route: /settings/people
- notes: …
- screenshot: e2e/runs/2026-09-08-163012/S-01-step-4.png   # on fail
- bug: e2e/runs/2026-09-08-163012/bugs.md#s-01   # if this fail is a product bug
```

`bugs.md` entry (append only):

```markdown
### S-01
- route: /settings/people
- persona: readonly
- step: 4
- what happened: …
- what was expected: …
- screenshot: e2e/runs/2026-09-08-163012/S-01-step-4.png
```

A skip for `requires_db` is not a failure and is not a bug.

## New scenarios

Copy [`scenario-template.md`](scenario-template.md). Routes stay relative. Anchors stay in the cast/site table.
