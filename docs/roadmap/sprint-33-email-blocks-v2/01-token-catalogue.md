# S33-01 — Token catalogue

## Context

Token knowledge is spread over three places today:

- `App\Support\Automation\SubjectTokenBag::vocabulary()` — a flat list of paths used
  by the inbox composer token menu (`InboxController`).
- `TemplatePublishValidator::sampleContext($purpose)` — a hand-built sample context
  used to render a draft at publish time. It is a copy of the bag and drifts as
  soon as a root is added (flagged in the S31 review: `site.*`, `unsubscribe_url`).
- The email builder's token menu in the panel.

None of them carries a description, a type or a sample value, and none says which
tokens make sense for which template purpose. S34's assistant needs all of that, and
the operator's token menu does too.

## Scope

**In:** `TokenCatalog`, purpose-based availability, sample values, one read endpoint,
migrate the three consumers onto it.
**Out:** new token roots beyond what the bag resolves today (except the
descriptive metadata), the automation TokenPicker (still open in `10`).

## Schema changes

None. The catalogue is code.

## Implementation notes

- `App\Support\Templates\TokenCatalog` (new namespace `App\Support\Templates`).
- One definition per token:

  ```php
  new TokenDefinition(
      path: 'contract.balance_owed',
      group: TokenGroup::Contract,          // contact | contract | deal | site | links | marketing
      type: TokenType::Money,               // text | money | date | url | number
      labelKey: 'templates.tokens.contract_balance_owed',
      descriptionKey: 'templates.tokens.contract_balance_owed_help',
      sample: '184.90',                     // string; money as string (invariant 10)
      purposes: [TemplatePurpose::Debt, TemplatePurpose::System, ...],
  )
  ```

- `TokenCatalog::all(): list<TokenDefinition>`,
  `forPurpose(TemplatePurpose): list<TokenDefinition>`,
  `sampleContext(TemplatePurpose, string $locale): RunContext`,
  `isAvailable(string $path, TemplatePurpose): bool`.
- Availability matrix (start here, adjust at implementation and record the final
  table in `06-communications.md`):

  | Group | general | debt | lead | offer | system | marketing | newsletter | announcement |
  |---|---|---|---|---|---|---|---|---|
  | contact.* | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
  | contract.* | ✓ | ✓ | – | – | ✓ | – | – | ✓ |
  | deal.* | ✓ | – | ✓ | ✓ | – | – | – | – |
  | pay_link | – | ✓ | – | – | ✓ | – | – | – |
  | preferences_url, unsubscribe_url | – | – | – | – | – | ✓ | ✓ | ✓ |

  `contract` purpose (documents) keeps its own smart blocks and is not changed.
- `sampleContext()` builds the same `RunContext` shape the renderer gets at send
  time, filled from each definition's `sample` (localised dates/money formatted by
  the renderer, not the catalogue).
- Migrate consumers:
  - `TemplatePublishValidator::sampleContext()` → `TokenCatalog::sampleContext()`.
    Delete the hand copy.
  - `InboxController` composer token list → `TokenCatalog::forPurpose()` (paths only,
    same response shape).
  - `SubjectTokenBag::vocabulary()` → derived from the catalogue (`array_map(path)`),
    kept as a thin wrapper so callers outside this task don't break.
- A test asserts every catalogue path resolves through the existing bag builders
  (`contractBag`, deal/contact bags) for a seeded subject — the catalogue can't list a
  token the resolver can't fill.
- Unavailable token in a draft = publish **warning**, not error (existing behaviour
  for unresolved tokens). S34 treats it as an error for the assistant only.

## API surface

| Method | Path | Permission | Response |
|---|---|---|---|
| `GET` | `template-families/{f}/tokens` | `template.manage` | `[{path, group, type, label, description, sample, available}]` for the family's purpose; unavailable tokens included with `available: false` so the menu can show them disabled |

Labels and descriptions are returned translated for the request locale.

## Panel surface

- Email builder **Insert token** menu reads the endpoint: grouped list, description
  as secondary text, sample value on hover, unavailable tokens disabled with
  "Not available for {purpose} templates".
- Composable `useTemplateTokens(familyId)`; type `TemplateToken` in
  `app/types/email-builder.ts`.
- i18n: `templates.tokens.*` labels/help for every token, en/es/fr.

## Invariants

- **10** — money samples are strings.
- **14** — any activity descriptions stay machine keys (none added here).

## Acceptance criteria

- [ ] Publish validator, inbox composer and builder token menu all read the catalogue;
      grep finds no other hard-coded token list.
- [ ] A debt template's menu offers `contract.balance_owed` and `pay_link`; a
      newsletter template shows them disabled and offers `unsubscribe_url`.
- [ ] Every catalogue path resolves for a seeded subject.
- [ ] Publish warnings are unchanged for existing seeded templates.

## Tests required

| Test | Asserts |
|---|---|
| `TokenCatalogTest::every_path_resolves` | Catalogue ⊆ resolver |
| `TokenCatalogTest::availability_by_purpose` | Matrix rows above |
| `TokenCatalogTest::sample_context_matches_send_shape` | Same keys as a real `RunContext` |
| `TemplatePublishValidatorTest::uses_catalogue_samples` | No hand copy |
| `TemplateTokensEndpointTest::returns_purpose_availability` | Endpoint shape and permission |
