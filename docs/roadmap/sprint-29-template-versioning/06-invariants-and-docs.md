# S29-06 — Invariants, docs & tests

## New invariant (09-conventions-and-invariants.md)

> **74. Published template content is immutable.** `template_versions` with
> `status = published` and their `template_variants` are never updated or deleted —
> enforced by model guards and a Postgres trigger. Edits happen on the single draft
> per family; publishing creates the next version. "Current version" is derived
> (highest published `version_number`), never stored. Live senders resolve the current
> version through `TemplateResolver`; pinning consumers (contract documents, campaigns)
> store `template_version_id`. Every templated outbound message records its version in
> `messages.detail.template`.

## Doc updates

- `06-communications.md`: template families section — versions, resolver split,
  provenance key.
- `04-crm-pipeline.md`: contract documents record `template_version_id`; regenerate
  uses latest published.
- `12-automation-engine.md` / `13-playbooks.md`: `template_not_published` failure;
  activation refusal.
- `10-open-decisions.md`: decided V1–V10; deferred: publish approval permission,
  version diff view, WhatsApp-into-families.

## Test checklist

- [x] Factory helper creates family → published v1 → variants; seeders use it.
- [x] Immutability: API 422, Eloquent exception, Postgres trigger (pgsql-only test group).
- [x] Lifecycle: draft create/409, publish validations, discard, restore.
- [x] Resolver: current vs pinned, ladder unchanged, `TemplateNotPublished`.
- [x] Handler tests: email + SMS record `detail.template`; mid-run publish switches content.
- [x] Usage counter counts `send_sms` and contract documents.
- [x] Asset GC keeps assets referenced only by old versions.
- [x] `RouteAuthCoverageTest` / `PermissionCoverageTest` pass with new routes.
