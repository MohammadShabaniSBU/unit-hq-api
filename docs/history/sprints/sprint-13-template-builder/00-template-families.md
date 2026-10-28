# S13-00 — Template families & locale variants

## Context

The multi-language requirement lands as structure: a **template family** (identity,
purpose, ownership) with **per-locale variants** (content). Email and SMS share the
model; WhatsApp reuses its *resolution* logic but keeps its own registry (03) because
approval state lives per-language there anyway. Everything referencing "a template"
today points at what becomes the family.

## Scope

**In:** schema rework, locale resolution helper, migration of existing `EmailTemplate`
rows, renderer contract updates, SMS templates (the cheap rider), reference integrity
for playbooks/composer. **Out:** the builder UI (01), WhatsApp registry (03), per-site
template overrides (deferred, recorded).

## Schema changes

```sql
CREATE TABLE template_families (
    id BIGSERIAL PRIMARY KEY,
    channel VARCHAR(16) NOT NULL,            -- email | sms
    name VARCHAR(128) NOT NULL,
    purpose VARCHAR(24) NOT NULL DEFAULT 'general',
        -- general | debt | lead | offer | system   (filters pickers; debt kinds
        -- restrict to debt|general — the S09 allowedActions idiom applied to content)
    archived_at TIMESTAMP NULL,
    created_at TIMESTAMP, updated_at TIMESTAMP
);

CREATE TABLE template_variants (
    id BIGSERIAL PRIMARY KEY,
    template_family_id BIGINT NOT NULL REFERENCES template_families(id),
    locale VARCHAR(5) NOT NULL,              -- en | es | fr (the panel's set)
    subject VARCHAR(500) NULL,               -- email
    blocks JSONB NULL,                       -- email v2 block document (01)
    body_html TEXT NULL,                     -- rendered cache? NO — see below
    body_text TEXT NULL,                     -- sms body / email text-part
    updated_by BIGINT NULL REFERENCES employees(id),
    created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE UNIQUE INDEX tv_family_locale_idx ON template_variants (template_family_id, locale);
```

No `body_html` cache column: rendering happens at send/preview from `blocks`
(legacy variants carry their old HTML in a `legacy_html` nullable column instead —
migrated content that predates blocks renders as-is until edited, at which point the
builder imports it as a single raw-HTML block; honest, no lossy auto-conversion).

**Migration:** each existing `EmailTemplate` → one family + one variant in the
template's evident locale (default `es` if the install's content is Spanish — inspect,
don't guess blindly; the command reports its locale assignments for review before
committing with `--confirm`). `email_template_id` references (playbook params,
composer) repoint to `template_family_id` — one rename sweep, grep-audited.
`EmailTemplate` model/table retire after.

## Behaviour

**Resolution.** `TemplateResolver::variant(TemplateFamily $f, Contact $c, ?Site $site):
TemplateVariant` — locale ladder: contact's explicit locale (a nullable
`contacts.locale` lands here — tiny, long overdue) → site country language (the S03
interim rule, now formalised) → `en` → any. Missing-everywhere is impossible while a
family requires ≥1 variant (enforced: last variant undeletable). The resolver is the
**only** way senders/renderers obtain content — grep-rule.

**Renderer contract.** `EmailTemplateRenderer` takes a *variant* (blocks → 01's
HTML renderer; legacy → passthrough) + token context; `SmsTemplateRenderer` (new,
trivial) does body+tokens. Both surface unresolvable tokens as send-time warnings on
the message detail, not silent blanks (an email greeting "Dear ," is a trust incident).

## Acceptance criteria

- [ ] Families + variants CRUD; unique locale per family; last-variant guard; purpose
      filters honoured by the debt/lead pickers.
- [ ] Migration report → confirm flow; every prior reference resolves; legacy HTML
      renders byte-identically pre/post (fixture).
- [ ] Resolution ladder table-tested incl. contact-locale override and the any-fallback.
- [ ] Unresolvable tokens warn on the message, render as empty-with-marker in preview.
- [ ] SMS templates usable end-to-end (composer picker lands in 04; the model +
      renderer here).

## Tests required

| Test | Asserts |
|---|---|
| `TemplateFamilyTest::structure_guards` | Locale unique, last-variant, purpose filter |
| `TemplateMigrationTest::report_confirm_reference_sweep` | Zero dangling refs |
| `TemplateResolverTest::locale_ladder` | Four rungs |
| `RendererTest::legacy_passthrough_identical` | The no-loss promise |
| `RendererTest::token_warnings_not_blanks` | Trust incident prevention |
