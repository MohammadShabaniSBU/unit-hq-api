# S35-03 — Eval suite

## Context

Prompt and model changes silently change output. Without a suite, the first signal
is an operator undoing everything. The suite checks properties that must hold for
every good answer, not a golden email.

## Scope

**In:** eval cases, runner command, CI job, results artefact.
**Out:** LLM-as-judge scoring (record as follow-up in `10`), online evals.

## Schema changes

None.

## Implementation notes

- Cases in `tests/Evals/TemplateAssist/cases/*.yaml`, about 30:
  ```yaml
  id: rate-increase-side-by-side
  purpose: system
  locale: es
  start: empty            # or a fixture path from S33-05
  prompt: "Aviso de subida de tarifa, tarifa actual y nueva lado a lado, tono cercano"
  assert:
    valid: true
    has_layout: [50_50]
    tokens_include: [rate_change.new_amount]   # adjust to catalogue paths
    tokens_subset_of_purpose: true
    max_primary_buttons: 1
    subject_max_chars: 60
    language: es
    no_legal_footer: true
  ```
  Coverage: every purpose's starters (S34-05), follow-up edits on seeded templates,
  selection-scoped edits, translation (00), image placement (01), one adversarial
  case per assistant-only check (asks for an unavailable token, asks for an
  unsubscribe line, asks for an external image URL).
- Runner: `php artisan template-assist:eval {--case=} {--model=} {--repeat=1}` drives
  the real agent through `DocumentWorkspace` without HTTP or the panel, against the
  configured provider. Writes `storage/app/evals/template-assist/{timestamp}.json`
  with per-case pass/fail, the produced document, tool-call count, tokens, latency.
- Language assertion: a small stop-word heuristic per locale (en/es/fr) on visible
  text; no external service.
- CI: separate GitHub Actions workflow, nightly and on changes to
  `resources/prompts/template-assistant.md`, `app/Ai/Agents/TemplateAssistantAgent.php`,
  `app/Ai/Tools/TemplateAssist/**`, `app/Support/Templates/**`. Fails below 90 %.
  Provider key from repository secrets. Never part of `php artisan test`.

## API surface

None.

## Panel surface

None.

## Invariants

- Evals use seeded/fixture templates only — no contact data (invariant 94).

## Acceptance criteria

- [ ] At least 30 cases, covering every purpose and every assistant-only check.
- [ ] `--repeat=3` reports per-case pass rate.
- [ ] Nightly workflow runs and uploads the results artefact.
- [ ] Baseline pass rate recorded in this sprint folder (`eval-baseline.md`).

## Tests required

| Test | Asserts |
|---|---|
| `EvalAssertionsTest::*` | Each assertion type against hand-made documents (unit, no model) |
| `EvalRunnerTest::writes_results_with_fake_agent` | Runner plumbing with the SDK fake |
