# S34-01 — Assist context

## Context

The model writes good emails only when it knows what this template is for, which
tokens it may use, what the brand looks like, and which blocks exist. It must not
know anything about real tenants (T7). One builder assembles that context so the
agent, the evals (S35) and the tests all see the same thing.

## Scope

**In:** `AssistContext` value object and builder, compact serialisation for the
prompt, "used by" summary, PII guard test.
**Out:** the prompt text itself (02).

## Schema changes

None.

## Implementation notes

- `App\Support\Templates\Assist\AssistContext::for(TemplateFamily $family, string $locale, ?array $selection): self`
- Fields:

  | Field | Source |
  |---|---|
  | `channel` | `family.channel` (only `email` allowed this sprint; others throw `assist.channel_unsupported`) |
  | `purpose` | `family.purpose` (`TemplatePurpose`) |
  | `send_class` | `marketing` for `marketing` / `newsletter` purposes, else `transactional` — the same mapping senders use; reuse it, don't re-derive |
  | `locale` | requested locale; must be a variant of the draft |
  | `tokens` | `TokenCatalog::forPurpose()` → `[{path, type, description, sample}]` (available only) |
  | `blocks` | block types + param rules for the email channel, generated from `EmailBlockDocument` (one method `describeForAssistant()` so the list can't drift) |
  | `layouts` | preset list |
  | `brand` | `EmailBrand::current()` — colours, font, `has_logo` (never the asset URL) |
  | `used_by` | counts and names of automations, playbooks and campaigns referencing the family (the same query that feeds the "Used by N" banner) — names only |
  | `selection` | `{kind, id, path}` resolved against the posted document, or null |

- `toPromptBlock(): string` — compact, stable text (sorted keys, no pretty-printing of
  JSON beyond one level) so prompt caching works across turns of a session.
- **PII guard.** The builder must not query `contacts`, `contact_channels`,
  `contracts`, `charges`, `payments`, `invoices`, `messages`, `message_threads`,
  `delinquency_*`. Enforce with a test that records queries
  (`DB::listen`) while building context for every purpose and fails on any of those
  tables.

## API surface

None.

## Panel surface

None.

## Invariants

- **92** — tokens come from `TokenCatalog` only.
- **T7** (new invariant in 06) — no CRM records in assistant context.

## Acceptance criteria

- [ ] Context for a `debt` family lists `contract.balance_owed` and `pay_link`;
      for `newsletter` it lists `unsubscribe_url` and not `pay_link`.
- [ ] `send_class` matches what the email sender would use for the purpose.
- [ ] Building context for every purpose touches none of the guarded tables.
- [ ] `toPromptBlock()` is byte-identical for the same inputs.
- [ ] A selection id not in the document returns `selection = null` (no error).

## Tests required

| Test | Asserts |
|---|---|
| `AssistContextTest::tokens_by_purpose` | Catalogue reuse |
| `AssistContextTest::send_class_matches_sender_mapping` | No re-derivation |
| `AssistContextTest::no_crm_tables_queried` | PII guard, every purpose |
| `AssistContextTest::prompt_block_stable` | Cache-friendly serialisation |
| `AssistContextTest::block_catalogue_matches_validator` | `describeForAssistant()` ≡ `TYPES` |
