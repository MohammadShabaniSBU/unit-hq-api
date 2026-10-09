# S29-04 — Panel: draft/publish, history, pickers

## Context

Affected pages: `marketing/templates/email/[id].vue`, `documents/[id].vue`, `sms.vue`,
composables `useEmailTemplates.ts`, `useSmsTemplatesList.ts`, and every template
picker (automation `send_email`/`send_sms` node config, playbook step editor, inbox
composer, contract document generator).

## Editor states

| State | UI |
|---|---|
| Published, no draft | Read-only content + **Edit** (creates draft via `POST …/versions`). Badge `v4 · Published`. |
| Draft exists | Editable. Badge `Draft v5 (based on v4)`. Banner: "Changes aren't live until you publish. Used by N automations/playbooks/contracts." Buttons **Publish**, **Discard draft**. |
| Only a draft (new template) | Same as draft + note "Not sendable until published." |

- **Publish** opens a confirm modal showing usage count and any token warnings from
  the publish response.
- **Save** keeps its explicit behaviour (`handleSave`) — saves the draft only.
- Locale tabs operate on the draft's variants.
- Existing `saveVariant(variantId, …)`: the variant id changes when a draft is created
  from published — the composable must re-read `draft_version.variants` after the
  first save and switch ids.

## History drawer

List from `GET …/versions`: number, status, published at/by, "based on", locales.
Click → read-only view of that version (reuse the builder in read-only mode / the PDF
preview for documents). Action **Restore as new draft** (disabled while a draft
exists, with a hint to discard or publish first).

## Lists & pickers

- Template lists show current version number and an "Unpublished changes" chip.
- All **live-send pickers** filter to families with `current_version != null`.
  Add `?sendable=1` to `GET template-families` so filtering is server-side.
- i18n keys for all new strings in `en`, `es`, `fr`.

## Acceptance criteria

- [ ] Edit → save → reload: draft persists; published content unchanged in preview of v4.
- [ ] Publish → list badge updates; draft gone; history shows v5.
- [ ] Restore v2 → draft v6 opens in editor with v2 content.
- [ ] New template doesn't appear in the automation picker until published.
