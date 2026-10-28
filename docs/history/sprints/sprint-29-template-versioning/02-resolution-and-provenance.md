# S29-02 — Resolution, senders & provenance

## Context

`TemplateResolver::variant()` is the sole content path (grep-rule from S13-00). It
currently reads `$family->variants`. It must read the **latest published version**
for live sends and any explicit version for pinned consumers.

## Resolver

```php
TemplateResolver::variant(TemplateFamily $f, Contact $c, ?Site $s): TemplateVariant
    // latest published; throws TemplateNotPublished if none
TemplateResolver::variantOf(TemplateVersion $v, Contact $c, ?Site $s): TemplateVariant
    // pinned / preview / test-send; same locale ladder
```

The locale ladder is unchanged and shared by both.

## Callers to update

| Caller | Change |
|---|---|
| `SendEmailHandler`, `SendSmsHandler` | Use `variant()`; `TemplateNotPublished` → step fails with `template_not_published` (mirrors WhatsApp's `template_not_approved`). Drop `->with('variants')` eager loads. |
| `PlaybookCompiler` / playbook step validation | Refuse save/activate when the family has no published version. |
| `TriggerConfigValidator` / automation activate | Same refusal for `send_email` / `send_sms` nodes. |
| `InboxController` (compose, reply, template picker at ~L873/894) | `variant()`; picker lists only families with a published version. |
| `TemplateFamilyController::preview / testSend` | `variantOf()` on the requested version (draft allowed). |
| `TemplateFamilyUsage` | Add contract-document count; also count `send_sms` (currently only `send_email` is counted — existing bug, fix here). |
| `TemplateAsset::isReferenced` | Unchanged — now scans all versions, so assets used by historical versions are never GC'd. Correct: sent emails embed the public asset URL. Add a test. |

## Provenance on messages

No schema change. Senders receive a `TemplateProvenance` value object and write it to
`messages.detail.template`, mirroring `detail.whatsapp_template.resolution`:

```json
"template": { "family_id": 12, "version_id": 88, "version_number": 4,
              "variant_id": 301, "locale": "es", "preferred_locale": "fr" }
```

Pass it through `SendContext` (or an explicit `detail` arg — match whatever
`EmailSender::send(... ?array $detail)` already does). Inline (non-template) sends
write nothing.

## Acceptance criteria

- [ ] Playbook running while a draft is edited keeps sending the published content;
      after publish, the next step sends the new version.
- [ ] Family with only a draft: automation activation refused; inbox picker hides it.
- [ ] Every templated outbound message has `detail.template` with the correct version.
- [ ] Grep test: no code outside `TemplateResolver` reads `template_variants` for sending.
