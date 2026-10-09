# S29-03 — Contract documents pin versions

## Context

`contract_documents` stores `template_family_id` + `template_variant_id`. After 00 the
variant row is immutable, so the FK is already trustworthy — but the version should
be explicit for queries ("which contracts were generated from v3 of the rental
contract?") and for the regenerate rule.

## Schema

Edit `…000830_create_contract_documents_table.php` in place (no data, see 00):

```sql
template_version_id BIGINT NOT NULL REFERENCES template_versions(id) ON DELETE RESTRICT,
CREATE INDEX cd_template_version_idx ON contract_documents (template_version_id);
```

## Behaviour

- **Generate:** resolve the latest published version (`variant()`); store family,
  version and variant. A document family with no published version → 422
  `errors.documents.template_not_published`.
- **Explicit `template_variant_id` override** (locale override, existing): must belong
  to a **published** version of the same family, otherwise 422 `variant_mismatch`.
- **Regenerate (draft docs only — existing rule):** uses the **latest published**
  version, not the superseded document's variant, so a clause fix reaches unsent drafts.
  Locale override is preserved by re-resolving the same locale in the new version
  (fallback to ladder if that locale no longer exists, and log it).
- `contract.document.locale_overridden` activity gains `template_version_id`.
- `ContractDocumentResource` exposes `template_version: {id, version_number}`; the
  contract detail panel shows "Rental contract · v3".

## Acceptance criteria

- [ ] `template_version_id` always equals the version of `template_variant_id` (assert in generate + test).
- [ ] Regenerate after publishing v2 produces a document on v2; superseded doc keeps v1.
- [ ] Sent / signed documents still refuse regenerate (unchanged `regenerate_frozen`).
