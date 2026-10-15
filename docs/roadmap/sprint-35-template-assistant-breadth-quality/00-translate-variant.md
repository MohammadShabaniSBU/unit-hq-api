# S35-00 — Translate variant

## Context

A version publishes all locales together (S29 V1). Today adding `es` means rebuilding
the email by hand in the `es` tab. Translation is the highest-value, lowest-risk
assistant feature: structure is copied, only text changes, and correctness is
checkable.

## Scope

**In:** translate tool and endpoint, token/structure parity check, panel entry in the
language bar.
**Out:** machine translation outside the assistant; translating published versions
(always into the current draft).

## Schema changes

None. A translation is a normal assistant turn on a session for the **target** locale.

## Implementation notes

- New tool `TranslateFrom` (`source_locale`) available only when the target
  variant's document is empty. It loads the source variant's document from the
  **same draft**, clones it with fresh ids, and returns the list of text fields
  (`block_id`, param path, text) for the model to translate, then the model calls
  `SetTexts` (`[{block_id, path, text}]`) once (or a few times for long emails).
- `SetTexts` only writes string params already present (`heading.text`,
  `paragraph.html`, `button.label`, `list.items.*`, `callout.title`, `callout.html`,
  `info_rows.rows.*.label`, `footer_social.address`, `image.alt`) — never URLs,
  asset ids, values that are a bare token, layouts or types.
- Parity check after `SetTexts`: multiset of `{{tokens}}` per block equals the
  source's; same section/column/block skeleton (types and order). Mismatch →
  `assist.translation_parity` with the block id; the model retries.
- Subject translated with `SetSubject`.
- Prompt addition: keep tokens verbatim; keep tone; use the target locale's
  conventions for dates written in prose; don't translate brand or site names.

## API surface

| Method | Path | Notes |
|---|---|---|
| `POST` | `template-families/{f}/assist/translate` | `{source_locale, target_locale, template_version_id, client_message_id}` → creates the target variant on the draft if missing (existing `storeVariant` path), opens/creates the target-locale session, dispatches a turn with a fixed prompt. 202 like any turn. |

The variant creation is the operator's action (same as **Add language**); the
assistant still never writes the variant's content (invariant 93).

## Panel surface

- Language bar **Add language** gains "Translate from {current}" next to "Blank".
  It switches to the new locale tab, opens the Assistant tab and applies the turn
  like any other.
- i18n en/es/fr.

## Invariants

- **93** — content lands in editor state; the operator saves.
- **91** — translated document is a valid v2 doc.

## Acceptance criteria

- [ ] Translating both S33 demo templates en → es and en → fr yields identical skeletons
      and token multisets (automated over recorded turns).
- [ ] A translation that drops `{{pay_link}}` is rejected by the parity check.
- [ ] `TranslateFrom` on a non-empty target returns `assist.translation_target_not_empty`.
- [ ] URLs and asset ids are byte-identical to the source.

## Tests required

| Test | Asserts |
|---|---|
| `TranslateToolTest::skeleton_and_token_parity` | Q1 |
| `TranslateToolTest::set_texts_rejects_non_text_params` | No URL/asset edits |
| `TranslateToolTest::target_must_be_empty` | Guard |
| `TranslateEndpointTest::creates_variant_and_session` | Flow |
