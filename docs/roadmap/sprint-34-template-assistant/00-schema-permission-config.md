# S34-00 — Schema, permission & config

## Context

Two facts need storing: which SDK conversation belongs to which template family and
locale (so reopening a template resumes its chat and the copilot list can exclude
it), and what each turn did to the document (so undo, conflicts and quality metrics
work). Everything else — messages, tokens, cost — already has a home.

## Scope

**In:** two tables, models, permission, config keys.
**Out:** behaviour (01–03).

## Schema changes

New migrations (additive).

```sql
CREATE TABLE template_assist_sessions (
    id BIGSERIAL PRIMARY KEY,
    template_family_id BIGINT NOT NULL REFERENCES template_families(id) ON DELETE CASCADE,
    locale VARCHAR(5) NOT NULL,
    conversation_id VARCHAR(36) NOT NULL UNIQUE REFERENCES copilot_conversations(id),
    employee_id BIGINT NOT NULL REFERENCES employees(id),
    last_turn_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ
);
CREATE INDEX tas_family_locale_employee_idx
    ON template_assist_sessions (template_family_id, locale, employee_id, last_turn_at DESC);

CREATE TABLE template_assist_turns (
    id BIGSERIAL PRIMARY KEY,
    session_id BIGINT NOT NULL REFERENCES template_assist_sessions(id) ON DELETE CASCADE,
    call_id UUID NOT NULL UNIQUE,                 -- = ai_usage_events.call_id
    client_message_id VARCHAR(64) NOT NULL,
    template_version_id BIGINT NOT NULL REFERENCES template_versions(id),  -- the draft being edited
    selection JSONB NULL,                          -- {kind, id} at send time
    base_document JSONB NOT NULL,                  -- v2 doc posted by the panel
    base_hash CHAR(64) NOT NULL,                   -- sha256 of canonical JSON, computed server-side
    working_document JSONB NOT NULL,               -- mutated by tools during the turn
    ops JSONB NOT NULL DEFAULT '[]',               -- [{op…, inverse…}] in order
    subject_change JSONB NULL,                     -- {from, to} when set_subject ran
    op_summary JSONB NOT NULL DEFAULT '{}',        -- {added, edited, removed, moved, sections_added}
    status VARCHAR(16) NOT NULL DEFAULT 'running', -- running | completed | failed | applied | undone | conflict | discarded
    error_key VARCHAR(64) NULL,
    prompt_version VARCHAR(32) NOT NULL,
    tool_call_count SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ,
    UNIQUE (session_id, client_message_id)
);
CREATE INDEX tat_status_created_idx ON template_assist_turns (status, created_at);
```

Notes:

- `conversation_id` is `VARCHAR(36)` because `copilot_conversations.id` is a string
  uuid.
- Token counts and cost are **not** columns here — they live on `ai_usage_events`
  joined by `call_id` (invariant 48).
- `working_document` and `ops` are written only while `status = running`. After the
  turn completes, only `status`, `error_key` and `updated_at` change. Model guard;
  pgsql trigger rejecting updates to `base_document`, `working_document`, `ops`,
  `subject_change` when `OLD.status <> 'running'`.
- Status transitions (forward only, enforced in the model):
  `running → completed | failed`; `completed → applied | conflict | discarded`;
  `applied → undone`; `undone → applied` (redo).
- Templates documents contain no contact data, so these rows are outside
  `contacts:redact`. If an operator types personal data into the chat, it lives in
  `copilot_conversation_messages` — the same open gap as the copilot (recorded in 06).

## Implementation notes

- Models `App\Models\TemplateAssistSession`, `TemplateAssistTurn` with casts
  (`array` for JSON, enum for status `App\Enums\TemplateAssistTurnStatus`).
- Relation `TemplateFamily::assistSessions()`.
- `Permission::TemplateAssistUse = 'template_assist.use'`; in `RbacSystemRoleSeeder` grant it to every
  system role that already holds `TemplateManage` (`owner` receives all permissions
  automatically); frontend `Permission` enum updated.
- `config/ai.php` → `template_assist`:
  `daily_turn_limit` (default 100), `max_tool_calls` (12), `max_prompt_chars` (4000),
  `prompt_version` (`ta-1`).

## API surface

None (03).

## Panel surface

None (04).

## Invariants

- **48** — no cost columns; join to `ai_usage_events`.
- **45** — permission is fail-closed: routes added in 03 must be in `RoutePermissions`.

## Acceptance criteria

- [ ] Migrations run on SQLite and Postgres; trigger only on pgsql, model guard everywhere.
- [ ] Updating `ops` on a completed turn fails via Eloquent and raw SQL (pgsql).
- [ ] An illegal status transition (e.g. `applied → running`) throws.
- [ ] Deleting a template family cascades sessions and turns (families are archive-only
      in practice; cascade covers test teardown).
- [ ] `PermissionCoverageTest` passes with the new permission.

## Tests required

| Test | Asserts |
|---|---|
| `TemplateAssistSchemaTest::turn_content_frozen_after_running` | Guard + trigger |
| `TemplateAssistSchemaTest::status_forward_only` | Transition table |
| `TemplateAssistSchemaTest::unique_client_message_per_session` | Idempotency key |
| `PermissionCoverageTest` (existing) | New permission seeded |
