# S35-01 — Images from chat

## Context

"Put this photo of our Madrid site at the top" is a natural request. Images must
come from the template asset store (`template_assets`, public hashed route) so emails
never reference remote images, and the S34 checks already reject image blocks without
an `asset_id`.

## Scope

**In:** attach in composer, upload to asset store, image passed to the model with its
asset id.
**Out:** image editing/cropping, generation.

## Schema changes

None.

## Implementation notes

- Composer accepts png, jpg, gif, webp up to the existing template-asset size limit;
  max 3 per turn.
- Upload uses the existing template-asset upload endpoint first; the turn request
  carries `attachments: [{asset_id}]` only.
- Dispatcher loads each asset, verifies it belongs to the asset store and is an image,
  and passes it to the agent as an SDK image attachment plus a text line
  `asset_id={id}, {width}×{height}`.
- Prompt addition: use attached images only through `InsertBlocks` with
  `image.asset_id`; write meaningful `alt` text in the template locale.
- Stored conversation message keeps the attachment reference (SDK `attachments`
  column), not the bytes.

## API surface

`POST template-assist/sessions/{s}/turns` accepts `attachments: Array<{asset_id}>`
(validated: exists, image MIME, ≤ 3).

## Panel surface

Paperclip button and drag-and-drop on the composer, thumbnails above the input,
remove before sending. i18n en/es/fr.

## Invariants

- No remote images in rendered email.
- **94** — images are template assets, not business records.

## Acceptance criteria

- [ ] An attached image ends up as an `image` block with that `asset_id` and non-empty alt.
- [ ] An `asset_id` that isn't an image is rejected with 422.
- [ ] Attachment bytes are not stored in conversation messages.

## Tests required

| Test | Asserts |
|---|---|
| `TemplateAssistAttachmentTest::passes_image_with_asset_id` | Agent input |
| `TemplateAssistAttachmentTest::rejects_non_image_asset` | Validation |
| `TemplateAssistAttachmentTest::message_stores_reference_only` | Storage |
