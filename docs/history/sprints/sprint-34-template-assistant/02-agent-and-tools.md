# S34-02 — Agent & document tools

## Context

`CrmCopilotAgent` (`app/Ai/Agents`) shows the house pattern: Laravel AI SDK agent
with `Promptable`, `RemembersConversations`, `MetersUsage` middleware, provider and
model from `AiProviderRegistry`, tools in `app/Ai/Tools`. The template assistant
follows it with a different tool set: tools that read and edit one document.

## Scope

**In:** agent class, prompt file, `DocumentWorkspace`, the tools, op/inverse
generation server-side, recorded turn fixtures for the panel.
**Out:** HTTP layer (03), panel (04).

## Schema changes

None (uses 00).

## Implementation notes

### Agent

```php
#[Timeout(120)]
#[WithoutBroadcasting(ToolCall::class, ToolResult::class)]
final class TemplateAssistantAgent implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(public Employee $employee, public int $turnId) {}
    public function middleware(): array { return [MetersUsage::class]; }
    public function provider(): ?string { return app(AiProviderRegistry::class)->applyActiveCredentials(); }
    public function model(): ?string { return app(AiProviderRegistry::class)->activeModel(); }
    public function instructions(): string { /* prompt file + AssistContext::toPromptBlock() */ }
    public function tools(): iterable { /* below, all bound to $this->turnId */ }
}
```

- Prompt lives in `resources/prompts/template-assistant.md`, versioned with code;
  its version string is `config('ai.template_assist.prompt_version')` and is stored on
  the turn.
- The agent is constructed with the turn id only (serialisable for the queue). Tools
  load the turn row, never the panel's state.

### `DocumentWorkspace`

`App\Support\Templates\Assist\DocumentWorkspace::forTurn(int $turnId)`

- Loads `working_document` with `lockForUpdate` inside a transaction per tool call.
- `apply(BlockOp $op): array{doc, inverse}` — PHP port of the panel's `applyOps`
  (same op names and the same shrink-layout rule). Generates ids server-side
  (`sec_`/`col_`/`blk_` + 8 hex via `random_bytes`).
- After each op: `EmailBlockDocument::validate()` on the whole document **plus**
  assistant-only checks (below). On failure nothing is saved and the tool returns
  the error key and path to the model.
- On success: saves `working_document`, appends `{op, inverse}` to `ops`, updates
  `op_summary`, increments `tool_call_count`.
- Refuses when `tool_call_count >= max_tool_calls` → `assist.tool_budget_exhausted`.

Assistant-only checks (stricter than the operator's save):

| Check | Error key |
|---|---|
| Token not in `TokenCatalog::forPurpose()` | `assist.token_unavailable` |
| `raw_html` block inserted | `assist.raw_html_forbidden` |
| `image` without `asset_id` (no external URLs) | `assist.image_requires_asset` |
| Text that looks like a legal footer / unsubscribe line on a marketing purpose (heuristic list per locale: "unsubscribe", "darse de baja", "se désabonner", …) | `assist.legal_footer_forbidden` |

### Tools (`app/Ai/Tools/TemplateAssist/`)

| Tool | Input | Effect |
|---|---|---|
| `GetDocument` | – | Compact document (ids, types, short text previews) |
| `ListTokens` | – | Available tokens with type, description, sample |
| `AddSection` | `index`, `layout`, `params?` | Empty section with columns |
| `SetSectionLayout` | `section_id`, `layout` | Shrink rule from S33-04 |
| `UpdateSection` | `section_id`, `params` | |
| `InsertBlocks` | `column_id`, `index`, `blocks[]` (type + params, no ids) | Ids assigned server-side |
| `UpdateBlock` | `block_id`, `params` (partial) | Merge then validate |
| `MoveBlock` | `block_id`, `to_column_id`, `to_index` | |
| `MoveSection` | `section_id`, `to_index` | |
| `RemoveNode` | `id` | Section (with contents) or block |
| `SetSubject` | `subject` (≤ 120 chars) | Stored in `subject_change`, not in the document |

Each tool's schema is strict (JSON Schema via the SDK tool definition); unknown
fields are rejected. Tool results are short: `ok` + new ids, or `error_key` + `path`
+ one-line hint.

### Prompt essentials

Work only through tools; read the document first when it isn't empty; use only
listed tokens with `{{path}}` syntax; never write legal footers or unsubscribe text
(marketing sends append them); one primary button per email; prefer `info_rows` for
figures and `callout` for warnings; subject under 60 characters; write in the
template's locale; if a selection is given, change only that node unless asked
otherwise; end with one or two sentences saying what changed. No claims about
prices or dates beyond tokens.

### Fixtures for the panel

Record 6 real turns (empty → rate increase, follow-up tone change, layout change,
selection-scoped edit, a failed token, a budget exhaustion) as
`tests/Fixtures/template-assist/turns/*.json` (`base_document`, `ops`,
`working_document`, `status`). The panel's applier tests (04) use them.

## API surface

None.

## Panel surface

None.

## Invariants

- **91** — every op leaves a valid v2 document.
- **92** — tokens from the catalogue.
- **T6/T7** (06) — the agent has no tool that reads business data or writes a template.

## Acceptance criteria

- [ ] PHP `apply` and panel `applyOps` produce identical documents for every op over
      the shared fixtures (same op JSON in, same doc out, ids excluded where generated).
- [ ] A tool call that would produce an invalid document changes nothing and returns
      the error key.
- [ ] `{{contract.balance_owed}}` inserted into a newsletter template is rejected with
      `assist.token_unavailable`.
- [ ] After 12 tool calls the next one returns `assist.tool_budget_exhausted` and the
      turn keeps the last valid document.
- [ ] The agent's tool list contains only the tools above (test asserts the list).

## Tests required

| Test | Asserts |
|---|---|
| `DocumentWorkspaceTest::op_and_inverse_round_trip` | Every op |
| `DocumentWorkspaceTest::parity_with_panel_fixtures` | PHP ≡ TS applier |
| `DocumentWorkspaceTest::invalid_op_leaves_document` | Atomic tool calls |
| `DocumentWorkspaceTest::assistant_only_checks` | Four error keys |
| `DocumentWorkspaceTest::tool_budget` | Limit |
| `TemplateAssistantAgentTest::tool_surface_is_closed` | No CRM tools |
| `TemplateAssistantAgentTest::prompt_includes_context_block` | 01 wiring |
