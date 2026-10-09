# S29-01 — Draft / publish lifecycle API

## Context

The editor keeps working on variants, but only on the family's **draft**. Publishing
freezes the draft into a new current version.

## Endpoints

| Method | Path | Behaviour |
|---|---|---|
| `GET` | `template-families/{f}/versions` | History list: number, status, published_at/by, locales, usage count (02). |
| `GET` | `template-families/{f}/versions/{v}` | One version with its variants (read-only if published). |
| `POST` | `template-families/{f}/versions` | Create the draft. Body `{from_version_id?}` — default = latest published. Copies all variants. 409 if a draft exists (return it). `from_version_id` on an old version = **restore**. |
| `POST` | `template-families/{f}/versions/{v}/publish` | Draft → published. Validations below. Returns the family resource. |
| `DELETE` | `template-families/{f}/versions/{v}` | Discard draft only. 422 on published. |

Existing variant routes are **re-scoped to the draft**:

- `POST/PUT/DELETE template-families/{f}/variants…` operate on the draft; if none
  exists, `storeVariant` / `updateVariant` **auto-create** it from the latest
  published (keeps the current panel working during rollout).
- Attempting to mutate a variant whose version is published → 422
  `errors.templates.version_published`.
- `preview` and `test-send` work on **any** version (testing a draft is the point).

`TemplateFamilyResource` gains:

```json
{ "current_version": { "id", "version_number", "published_at", "published_by", "variants": [...] } | null,
  "draft_version":   { "id", "version_number", "based_on_version_id", "variants": [...] } | null,
  "has_unpublished_changes": true }
```

## Publish validation

Inside one transaction with `SELECT … FOR UPDATE` on the draft row:

1. Draft has ≥ 1 variant.
2. Every variant renders (`EmailTemplateRenderer` / `SmsTemplateRenderer` /
   `ContractDocumentRenderer` against the purpose's sample context) without fatal
   errors. Unresolvable-token **warnings** are returned to the panel, not blocking.
3. Document channel: exactly one `signature_anchor` per variant (S14-01 rule, now
   checked at publish, not only at generation).
4. Flip `status = published`, set `published_at`, `published_by`.

## Immutability

- **Model guard:** `TemplateVariant` `updating` / `deleting` and `TemplateVersion`
  `updating` (except the draft→published transition) throw
  `PublishedTemplateImmutable` when the version is published.
- **Postgres trigger** (`template_variants` BEFORE UPDATE OR DELETE; `template_versions`
  BEFORE UPDATE OR DELETE): reject when the (old) version is published, except the one
  allowed status transition. SQLite: model guard only.

## Activity

Tier-2 `RecordsActivity` on the family (`LogChannel` communications):
`template.version.drafted`, `template.version.published` (`version_number`,
`based_on_version_id`), `template.version.discarded`, `template.version.restored`.
Machine keys only (invariant 14).

## Acceptance criteria

- [ ] Edit flow: PUT variant on a family with no draft creates draft v(n+1); v(n) untouched.
- [ ] Two concurrent "create draft" calls → one draft, one 409 (unique index).
- [ ] Publish with a broken document variant → 422 with the variant/locale named.
- [ ] Raw `UPDATE template_variants …` on a published row fails in Postgres.
- [ ] Restore v2 on a family at v5 → draft v6 with v2's content, `based_on_version_id = v2`.
