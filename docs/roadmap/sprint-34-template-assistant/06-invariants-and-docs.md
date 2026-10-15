# S34-06 — Invariants, docs & tests

## New invariants (`docs/09-conventions-and-invariants.md`)

> Assumes S33 added 91–92.

93. **The template assistant never writes a template.** Its tools edit a working
    document on `template_assist_turns`; the panel applies the result to editor
    state; only the employee's Save writes the draft and only Publish makes it live.
    No assistant endpoint, job or tool updates `template_versions` or
    `template_variants`. Turn content is frozen once the turn leaves `running`.
    *Enforced by* `TemplateAssistNoWritesTest`, `TemplateAssistSchemaTest`.
94. **Template assistant context contains no business records.** `AssistContext` and
    the assistant's tools read template structure, `TokenCatalog`, brand settings
    and usage names only — never contacts, contracts, billing or messages. Token
    previews use catalogue sample values. Tokens outside the family's purpose are
    rejected for the assistant even though an operator may save them with a warning.
    *Enforced by* `AssistContextTest::no_crm_tables_queried`,
    `TemplateAssistantAgentTest::tool_surface_is_closed`,
    `DocumentWorkspaceTest::assistant_only_checks`.

## Docs

- `14-ai-agents.md` — new section **Internal agents** (short): `CrmCopilotAgent`,
  `SummaryAgent`, `TemplateAssistantAgent`; how they differ from the customer-facing
  runtime; shared SDK conversation store; `ai_purpose` values.
- `06-communications.md` — template assistant: ops, turn lifecycle, apply/rebase,
  assistant-only checks.
- `01-stack.md` — panel page map: builder Assistant tab.
- `10-open-decisions.md`:
  - **Decided:** T1–T10.
  - **Undecided:** retention of assist sessions/turns; personal data typed into
    assistant chats (same gap as copilot conversations in the redaction row).
- `AGENTS.md` — routing row "Template assistant → `14-ai-agents.md` § Internal agents".

## Test matrix

| Area | Test class |
|---|---|
| Schema | `TemplateAssistSchemaTest` |
| Context | `AssistContextTest` |
| Workspace / tools | `DocumentWorkspaceTest`, `TemplateAssistantAgentTest` |
| API | `TemplateAssistDispatchTest`, `TemplateAssistTurnTest`, `TemplateAssistAuthTest`, `TemplateAssistNoWritesTest` |
| Copilot isolation | `CopilotControllerTest::excludes_assistant_conversations` |
| Publish provenance | `TemplatePublishTest::records_ai_assisted` |
| Panel | `assistApply.spec.ts`, `canonicalJson.spec.ts`, `starters.spec.ts` |

## Acceptance criteria

- [ ] Invariants 93–94 in `09` with existing test names.
- [ ] Docs updated.
- [ ] Sprint exit criteria demonstrated on a freshly seeded database with a configured
      AI provider; screen recording linked in the PR.
- [ ] `php artisan test`, `bun run lint`, `bun run typecheck` green.
