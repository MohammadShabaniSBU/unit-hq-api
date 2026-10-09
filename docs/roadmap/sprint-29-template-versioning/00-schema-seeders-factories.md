# S29-00 — Schema, seeders & factories

## Context

Content currently hangs directly off the family (`template_variants.template_family_id`,
unique `(template_family_id, locale)`). We add `template_versions` and move the
variant's ownership to a version, keeping the family FK as a denormalised column.

**No production data exists**, so there is no backfill. Edit the original create
migrations in place and rebuild with `php artisan migrate:fresh` + `demo:seed`.
(Once a real install exists, this exception ends: schema changes ship as new
migrations again.)

## Schema changes

New migration **ordered between** `…000650_create_template_families_table` and
`…000660_create_template_variants_table` (e.g. `2026_08_05_000655_create_template_versions_table.php`):

```sql
CREATE TABLE template_versions (
    id BIGSERIAL PRIMARY KEY,
    template_family_id BIGINT NOT NULL REFERENCES template_families(id) ON DELETE RESTRICT,
    version_number INT NOT NULL,                 -- 1, 2, 3… per family
    status VARCHAR(16) NOT NULL DEFAULT 'draft', -- draft | published
    based_on_version_id BIGINT NULL REFERENCES template_versions(id),  -- restore / edit origin
    published_at TIMESTAMPTZ NULL,
    published_by BIGINT NULL REFERENCES employees(id) ON DELETE SET NULL,
    created_by BIGINT NULL REFERENCES employees(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ,
    CONSTRAINT tver_published_shape CHECK (
        (status = 'draft' AND published_at IS NULL) OR
        (status = 'published' AND published_at IS NOT NULL)
    )
);
CREATE UNIQUE INDEX tver_family_number_idx ON template_versions (template_family_id, version_number);
CREATE UNIQUE INDEX tver_one_draft_idx ON template_versions (template_family_id) WHERE status = 'draft';
CREATE INDEX tver_family_published_idx ON template_versions (template_family_id, version_number DESC) WHERE status = 'published';
```

Edit `…000660_create_template_variants_table.php`:

```sql
-- add
template_version_id BIGINT NOT NULL REFERENCES template_versions(id) ON DELETE CASCADE,
-- replace tv_family_locale_idx with
CREATE UNIQUE INDEX tv_version_locale_idx ON template_variants (template_version_id, locale);
```

Draft version numbers: a draft gets `max(version_number) + 1` at creation. Discarding
a draft frees the number (only drafts are deletable, so no published number is ever
reused). `ON DELETE CASCADE` on variants only ever fires for drafts — the trigger in
01 blocks deleting published versions.

Partial indexes need the existing `DB::getDriverName() === 'pgsql'` guard pattern for
SQLite test runs.

## Models

- `TemplateVersion` model + `TemplateVersionStatus` enum (`Draft`, `Published`).
- `TemplateFamily::versions()`, `::currentVersion()` (latest published, query — not a
  column), `::draft()`.
- `TemplateVariant::version()` belongs-to.
- `TemplateFamily::variants()` is **removed** (it would now return every locale of every
  version). Grep and replace callers; `TemplateFamilyResource` switches to
  current/draft version payloads (shape in 01).

## Seeders & factories

Every place that creates a family must now create a version. Add one helper and use
it everywhere so nobody hand-builds the three rows:

```php
TemplateFamilyFactory::published(['channel' => 'email', ...], variants: [...]);
// creates family → version 1 (published, published_at = now) → variants
```

Callers to switch:

- `database/seeders/ContractDocumentTemplateSeeder.php`
- `database/seeders/DebtPlaybookSeeder.php`
- `database/seeders/LeadChasePlaybookSeeder.php`
- `database/seeders/CelebrationAutomationsSeeder.php`
- `database/seeders/Demo/Journeys/JourneySupport.php`
- `tests/Support/Documents/CreatesContractDocumentFixtures.php`
- Feature tests under `tests/Feature/Communications/` that create families/variants directly.

## Optional cleanup (no data to migrate means these are dead code)

`email_templates` / `email_blocks` tables, `EmailTemplate` / `EmailBlock` models,
`MigrateTemplateFamiliesCommand` and `TemplateMigrationTest` exist only to migrate
pre-S13 templates. Nothing else references them. Delete them in this sprint or log
them for later. (`legacy_html` is **not** dead — the playbook/automation seeders use
it — leave it.)

## Acceptance criteria

- [ ] `migrate:fresh` on Postgres and SQLite succeeds.
- [ ] `demo:seed --fresh` produces every family with exactly one published v1 and
      the demo journeys still send/render.
- [ ] Full test suite green after factory/seeder switch.
