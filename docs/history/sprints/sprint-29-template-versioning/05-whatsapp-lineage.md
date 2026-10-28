# S29-05 — WhatsApp lineage

## Context

WhatsApp templates live in `whatsapp_templates`, not `template_families`. Approved
rows are already immutable (Meta rule), and Clone creates `{name}_v2` as a new draft.
What's missing is the link between a clone and its origin, so history can be shown
like other channels. **No migration into families** (decision V9).

## Schema

```sql
ALTER TABLE whatsapp_templates ADD COLUMN supersedes_id BIGINT NULL
    REFERENCES whatsapp_templates(id) ON DELETE SET NULL;
```

## Behaviour

- `WhatsappTemplateController::clone` sets `supersedes_id` to the source row.
- `GET whatsapp-templates/{id}` includes `lineage: [{id, name, language, status, decided_at}]`
  (walk `supersedes_id` back, capped at 20).
- Panel WhatsApp editor shows the lineage list (read-only).
- Messages already record template resolution in `detail.whatsapp_template` — no change.

## Acceptance criteria

- [ ] Clone of an approved template links back; lineage renders in the panel.
- [ ] Existing rows unaffected (`supersedes_id` null).
