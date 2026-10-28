# S13-03 — WhatsApp template manager

## Context

The "WhatsApp message builder" from the first brief, built as Meta's rules dictate: a
registry of templates whose real state lives at Meta, synced through the provider. The
builder is a constrained form (header/body/footer/buttons, numbered variables); the
product is the **approval lifecycle made visible** — draft → submitted → approved /
rejected(reason) / revoked — because that lifecycle is what operators can't see today
in any provider dashboard without leaving their workflow.

## Scope

**In:** `whatsapp_templates` registry, the constrained builder UI, submission via
`ManagesWhatsAppTemplates`, status sync (webhook where the provider offers it +
scheduled poll fallback — belt/braces, the S08 sweeper idiom), variable model +
sample values, per-language rows. **Out:** media headers (text v1, image header
recorded), template *editing* post-approval (Meta forbids — new template, old
archives; the immutability idiom arrives naturally), category migration flows.

## Schema changes

```sql
CREATE TABLE whatsapp_templates (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(128) NOT NULL,              -- meta-safe slug: lowercase_underscored
    language VARCHAR(8) NOT NULL,            -- es | en | fr … (Meta's codes)
    category VARCHAR(16) NOT NULL,           -- utility | marketing | authentication
    header_text VARCHAR(60) NULL,
    body TEXT NOT NULL,                      -- with {{1}}, {{2}} placeholders
    footer_text VARCHAR(60) NULL,
    buttons JSONB NULL,                      -- [{type: url|quick_reply, text, url?}]
    variables JSONB NOT NULL DEFAULT '[]',   -- [{index, label, token_default, sample}]
    status VARCHAR(16) NOT NULL DEFAULT 'draft',
        -- draft | submitted | approved | rejected | revoked | archived
    rejection_reason TEXT NULL,
    provider_template_id VARCHAR(128) NULL,
    submitted_at TIMESTAMPTZ NULL, decided_at TIMESTAMPTZ NULL,
    communication_account_id BIGINT NOT NULL REFERENCES communication_accounts(id),
    created_by BIGINT NULL, created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE UNIQUE INDEX wt_identity_idx ON whatsapp_templates (communication_account_id, name, language)
    WHERE status NOT IN ('archived');
```

`variables`: each `{{n}}` gets a **label** ("tenant first name"), a **token_default**
(the `TokenResolver` handle auto-filled at send — 04's magic), and a **sample** (Meta
requires samples at submission; also powers preview). Validation: placeholders
sequential from 1, every placeholder has a variable row, body ≤1024 chars, name
slug-valid, category required with the honesty helper text (README rule: dunning =
utility, nurture = marketing — and the debt/lead kinds will enforce it in 04).

## Behaviour

**Lifecycle.** Draft freely; **Submit** → adapter call with samples →
`submitted` + provider id; sync (webhook event where offered, plus an hourly poll of
non-terminal rows — the poll is authoritative, the webhook is latency) → `approved` /
`rejected` + reason verbatim (Meta's reasons are cryptic; render them raw with a help
link, don't paraphrase). **Revocation** (Meta pulls an approved template) arrives via
the same sync → `revoked` — and every playbook step or composer referencing it starts
skip-with-reason immediately (02's approval check reads live status; nothing cached).
Approved rows are read-only (schema-level: update guard except status/sync fields);
"Edit" clones to a new draft with `_v2`-suffixed name. Archive hides from pickers.

**Language families.** Same `name` across languages groups visually in the panel (and
in 04's resolution: pick by contact locale ladder among *approved* rows of the name,
fallback rung = any approved language of that name — a Spanish tenant getting the
English variant beats a skipped dunning step; the choice is logged on the message).

## Panel surface

Marketing → Templates → WhatsApp tab: grouped-by-name list (language chips coloured by
status, category badge, submitted/decided dates), status filters, the rejected-reason
prominent. Builder form: live phone-frame preview (the bubble, header/footer/buttons
rendered, samples substituted), variable table (label/token/sample per placeholder,
auto-detected as you type `{{`), category select with consequence copy, Submit with a
pre-flight checklist (samples present, name unique, category chosen). i18n
`templates.whatsapp.*`; the preview bubble is es-first by nature.

## Acceptance criteria

- [ ] Full lifecycle against a mocked adapter: draft→submit→approve and →reject(reason
      rendered raw) and later revoke → dependents skip within the hour (poll) or
      instantly (webhook fixture).
- [ ] Approved immutability: field updates rejected; clone-to-draft works; identity
      unique index respects archived.
- [ ] Placeholder/variable validation matrix (gaps, non-sequential, missing samples).
- [ ] Language grouping + the any-approved fallback logged on the message.
- [ ] Poll authoritative with webhooks disabled (the sweeper test, again).

## Tests required

| Test | Asserts |
|---|---|
| `WaTemplateLifecycleTest::submit_approve_reject_revoke` | + dependent skip |
| `WaTemplateTest::approved_readonly_clone_flow` | Meta's immutability honoured |
| `WaTemplateTest::placeholder_validation_matrix` | The form's teeth |
| `WaTemplateResolutionTest::locale_ladder_any_approved_logged` | 04's contract |
| `WaSyncTest::poll_without_webhooks` | Authoritative path |
