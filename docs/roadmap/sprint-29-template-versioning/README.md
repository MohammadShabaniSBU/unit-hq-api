# Sprint 29 — Template Versioning

## Goal

Make published template content **immutable**. Today `template_variants` rows are
edited in place (`PUT template-families/{f}/variants/{v}`), so an edit goes live
instantly for every automation, playbook and inbox send, and history can no longer
say what was actually sent. `contract_documents.template_variant_id` points at a row
that may have changed since the PDF was rendered.

This sprint inserts a **version** layer between family and variant:

```
template_families (identity)  →  template_versions (draft | published)  →  template_variants (locale content)
```

Edits happen on a single draft. Publishing freezes it. Live sends always resolve
the latest published version. Pinning consumers (contract documents now; campaigns
in the marketing sprint) store the exact version.

It is also the prerequisite for the marketing campaigns sprint: `campaign_sends`
references `template_version_id` and stores no content.

## Design decisions (lock at kickoff)

| # | Decision | Rationale |
|---|---|---|
| V1 | Version at **family level**; all locales publish together | "v4" means one exact set of content. A French-only fix is a new version; cheap. |
| V2 | **No backfill** — no production data exists. Original create migrations are edited in place; seeders/factories create published v1 | Cleanest schema (`template_version_id NOT NULL` from day one). Rule ends at the first real install. |
| V3 | Version `status` stores only `draft` / `published`. "Current" = highest published `version_number` | Derived-state rule (invariant 5). No `current_version_id` pointer to keep in sync. |
| V4 | Rollback = **restore as new draft**, then publish | Append-only history; no reordering or un-publishing. |
| V5 | At most **one draft** per family (partial unique index) | No merge problem between two editors. |
| V6 | Published versions and their variants are immutable. Enforced in the model **and** by a Postgres trigger | Same stance as I2: invariant lives in the DB, not only in app code. |
| V7 | Automations / playbooks / inbox **follow latest published**. Contract documents (and later campaigns) **pin** | Typo fixes reach long-running playbooks; legal and approved artefacts stay fixed. Every `messages` row records the version used either way. |
| V8 | Families with no published version are **not sendable** and hidden from live pickers | A brand-new template can't go out half-written. |
| V9 | WhatsApp keeps its registry. Only add lineage (`supersedes_id`) | Meta approval already makes approved rows immutable; migrating would fight the S13-03 sync for no gain. |
| V10 | `template_variants.template_family_id` stays (denormalised, immutable) | Keeps resolver, `assertVariantBelongs`, asset GC and existing queries unchanged. |

## Exit criteria

- [ ] Editing a template never changes what a running playbook/automation/inbox send
      uses until **Publish** is pressed.
- [ ] A published variant cannot be updated or deleted through the API, Eloquent, or
      raw SQL (trigger).
- [ ] Every templated outbound `messages` row records `{family_id, version_id,
      version_number, variant_id, locale}` in `detail.template`.
- [ ] Every contract document records the `template_version_id` that produced it;
      regenerate uses the latest published version and records it.
- [ ] Operator can view history, open any old version read-only, and restore it as a
      new draft.
- [ ] `migrate:fresh` + `demo:seed` produce published v1 templates and demo journeys
      still render and send.

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Schema, seeders & factories](./00-schema-seeders-factories.md) | 0.5 day |
| 01 | [Draft / publish lifecycle API](./01-lifecycle-api.md) | 1.5 days |
| 02 | [Resolution, senders & provenance](./02-resolution-and-provenance.md) | 1 day |
| 03 | [Contract documents pin versions](./03-contract-documents.md) | 0.5 day |
| 04 | [Panel: draft/publish, history, pickers](./04-panel.md) | 2 days |
| 05 | [WhatsApp lineage](./05-whatsapp-lineage.md) | 0.5 day |
| 06 | [Invariants, docs & tests](./06-invariants-and-docs.md) | 0.5 day |

**Total ≈ 6.5 days.** 00 → 01 → 02 are sequential; 03 and 05 can run in parallel after
02; 04 can start after 01's API shape is frozen.

## Out of scope

- Approval workflow before publish (separate `template.publish` permission) — recorded
  as follow-up; v1 publish uses `template.manage`.
- Visual diff between versions (history shows read-only views side by side only).
- Per-site template overrides (still deferred from S13).
- Migrating WhatsApp into `template_families`.
