# S35-06 — Invariants, docs & tests

## Invariants

> Assumes S34 added 93–94.

- Amend **93**: add "In HTML mode the assistant edits `working_html` with patches;
  every result is sanitised server-side before the panel receives it."
- Amend **94**: add "Images reach the assistant only as template assets."
- **95. Translated variants keep structure.** A variant produced by the assistant's
  translation has the source variant's section/column/block skeleton, token multiset,
  URLs and asset ids; only text params and the subject differ.
  *Enforced by* `TranslateToolTest::skeleton_and_token_parity`.

## Docs

- `06-communications.md` — translation, HTML mode, images.
- `14-ai-agents.md` § Internal agents — eval suite, quality report, rollout setting.
- `report-definitions.md` — template assistant report (EN + ES).
- `10-open-decisions.md`:
  - **Decided:** Q1–Q6.
  - **Undecided:** LLM-as-judge evals; contract-document assistant; SMS/WhatsApp
    assistants; assist session retention.
- `AGENTS.md` — no change beyond S34's row.

## Test matrix

| Area | Test class |
|---|---|
| Translate | `TranslateToolTest`, `TranslateEndpointTest` |
| Images | `TemplateAssistAttachmentTest` |
| HTML mode | `HtmlWorkspaceTest`, `TemplateAssistSchemaTest::html_columns_frozen` |
| Evals | `EvalAssertionsTest`, `EvalRunnerTest` |
| Report | `TemplateAssistantReportTest` |
| Rollout | `TemplateAssistRolloutTest` |

## Acceptance criteria

- [ ] Invariant 95 added and 93–94 amended, with existing test names.
- [ ] `eval-baseline.md` committed with the pass rate on `dev`.
- [ ] Docs updated.
- [ ] `php artisan test`, `bun run lint`, `bun run typecheck` green.
