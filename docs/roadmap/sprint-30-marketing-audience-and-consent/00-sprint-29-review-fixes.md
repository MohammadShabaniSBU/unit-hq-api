# S30-00 — Sprint 29 review fixes

Findings from reviewing the template-versioning implementation (commits `30357b9` →
`ebbe37f`). Fix before campaigns start pinning versions.

## 1. A published version can still gain or lose variants  (medium)

- `TemplateVariant` guards `updating` / `deleting` only — no `creating` guard, so a
  variant (new locale) can be inserted into a published version.
- The model guard reads `$variant->version()` through the **current** FK value, so
  changing `template_version_id` on a published variant to a draft passes the guard
  (row moves out of a published version).
- The Postgres trigger `tv_reject_published_mutation` is `BEFORE UPDATE OR DELETE`
  and only checks `OLD.template_version_id`.

**Fix**

- Trigger: `BEFORE INSERT OR UPDATE OR DELETE`; reject when the OLD version (UPDATE,
  DELETE) **or** the NEW version (INSERT, UPDATE) is published.
- Model: add `creating`; check `getOriginal('template_version_id')` *and* the new value
  on `updating`.
- Make `template_version_id` and `template_family_id` immutable on update (both
  trigger and model) — variants never move.
- Enforce family consistency: unique `(id, template_family_id)` on
  `template_versions` + composite FK `(template_version_id, template_family_id)` from
  `template_variants`. Today nothing stops a variant whose `template_family_id`
  disagrees with its version's family.

## 2. Contract documents can be generated from a superseded version  (medium, legal)

`ContractDocumentController::resolveVariant` accepts an explicit
`template_variant_id` from **any** published version of the family. After the lawyer
publishes v3 with a clause fix, a request carrying a v1 variant id still generates a
v1 contract.

**Fix:** an explicit `template_variant_id` must belong to the **current** published
version (`$published->id`). Otherwise 422 `errors.templates.variant_not_current`.
(If generating from an older version is ever needed, it becomes a separate
permission + activity entry — record as deferred.)

## 3. Publish validator duplicates the token bag  (low — but S31 adds tokens)

`TemplatePublishValidator::sampleContext()` hand-copies the keys of
`SubjectTokenBag` (plus `pay_link`, `deal`). When S31 adds `site.*`,
`unsubscribe_url`, `preferences_url`, publish will warn on legitimate tokens and
operators learn to ignore warnings.

**Fix:** add `SubjectTokenBag::sample(TemplatePurpose $purpose): array` next to the
real bag builders, used by both the validator and tests; one place to extend.

## 4. Small consistency items  (low)

- `publish()` and `draftConflict()` build `response()->json(...)` by hand; use the
  controller response helpers so the envelope matches other endpoints (add a
  `warnings` meta key to the helper if needed).
- `ContractDocumentController` returns the hard-coded English
  `'No variant exists for locale …'` — move to `lang/*/errors.php`.

## Acceptance criteria

- [ ] Inserting a variant into a published version fails (API, Eloquent, raw SQL on pgsql).
- [ ] Updating `template_version_id` / `template_family_id` on any variant fails.
- [ ] Generating a contract with a variant id from a non-current published version → 422.
- [ ] Validator and `SubjectTokenBag` share one sample source; existing
      `TemplateVersionLifecycleTest` / `TemplateProvenanceTest` / `DocSnapshotTest` green.
