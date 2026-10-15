# S33-05 — Seeders, factories & demo templates

## Context

No production data exists (S29 V2), so the seeded and factory data is the dataset.
It has to exercise v2 or nobody sees the new layouts in the panel, and S34's
assistant evals need realistic starting documents.

Template-writing seeders today: `DebtPlaybookSeeder`, `LeadChasePlaybookSeeder`,
`CelebrationAutomationsSeeder`, `Demo/Journeys/JourneySupport`, and
`TemplateFamilyFactory` (plus `CampaignSendFactory` indirectly).
`ContractDocumentTemplateSeeder` is documents and stays as is.

## Scope

**In:** emit v2 from every email-template seeder and the factory; two new demo
templates; brand defaults in the demo world.
**Out:** contract document seeds.

## Schema changes

None.

## Implementation notes

- Add a small builder, `Tests\Support\EmailDocBuilder` / `Database\Support\EmailDocBuilder`
  (one class used by seeders and factories):
  `EmailDocBuilder::make()->section('100', fn ($c) => $c->heading(...)->paragraph(...))->section('50_50', ...)->toArray()`.
  Ids come from a deterministic counter seeded by the template name — **no random
  draws** (demo stage generation rule).
- Convert existing seeded email templates to v2 by wrapping their current blocks in
  one `100` section (same content, no visual change).
- New demo templates, en and es, purpose-correct, published v1:
  - **Rate increase notice** (`system`): logo header; heading; paragraph; `50_50`
    section with `info_rows` "Current rate" / "New rate" and effective date; button
    "View my contract"; footer.
  - **Overdue reminder** (`debt`): logo header; `callout` warning with
    `contract.balance_owed`; `info_rows` (unit, amount, due date); button with
    `pay_link`; footer.
- Demo world sets brand settings (accent, text, background, font) and uploads a demo
  logo asset (file committed under `database/seeders/Demo/assets/`).
- `TemplateFamilyFactory` default state produces a v2 document with one section;
  add a `->legacyV1()` state for tests of the upcast path.

## API surface

None.

## Panel surface

None (data only).

## Invariants

- Demo stage generation performs no random draws.
- **74** — seeders create published versions through the normal publish path or
  the existing seeder helper, never by updating a published row.

## Acceptance criteria

- [ ] `migrate:fresh && php artisan demo:seed --fresh` succeeds; every seeded email
      variant has `version: 2`.
- [ ] Both new templates render without token warnings in preview with their purpose
      sample context.
- [ ] Demo journeys that send templated email still send (existing journey tests green).
- [ ] Running the seeder twice gives identical block ids.

## Tests required

| Test | Asserts |
|---|---|
| `DemoSeedTemplatesTest::all_email_variants_v2` | Seeded data shape |
| `DemoSeedTemplatesTest::new_templates_render_clean` | No token warnings |
| `EmailDocBuilderTest::deterministic_ids` | No random draws |
| `TemplateFamilyFactoryTest::legacy_v1_state` | Upcast path is testable |
