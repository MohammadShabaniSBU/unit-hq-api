# S18-06 — Panel: Settings → Insights

## Context

Everything in tasks 02–05 is invisible until an operator can connect an instance and define a
report without reading the API docs. This screen is where the feature is judged.

The hard part is the param editor. The mental model an operator already has, from the
automation engine's `action.create_object`, is **static versus dynamic** — so use that
vocabulary, that visual treatment, and ideally that component. What they must never do is type
a param slug: slugs come from the provider (task 05), the operator only chooses a binding.

## Scope

**In:**
- Settings → Insights, two tabs: **Connections** and **Reports**
- Connection form per provider, driven by `GET /api/settings/analytics-providers`
- Verify / set default / archive
- Report builder: source, resource picker, param editor, labels, visibility, scope mode
- Reorder + hide across native and embedded reports in one list
- Full `en` / `es` / `fr` keys

**Out:**
- Rendering reports (task 07)
- Drag-and-drop reorder — arrow reorder ships first, exactly as object customization did

## Panel surface

### Settings → Insights → Connections

List of `analytics_accounts`: display name, provider, base URL, status chip
(`connected` / `error` / `pending`), default badge, last verified. Row actions: verify, set
default, edit, archive.

The credential form is **generated** from `credential_fields` in the providers registry —
never a hardcoded list of Metabase fields in Nuxt. Adding Superset later must not require
touching this component.

Credential inputs follow the comms settings pattern exactly: existing secrets render as
`••••••ab12`, an empty field on submit means unchanged, and the helper text says so. A
`credentials_unreadable` account renders a prominent "re-enter credentials" state rather than
a broken form.

Two pieces of copy that prevent support tickets:

- Under the status chip: a green tick means the **API key** works. Embedding is proven by the
  first report that renders — because the signing key cannot be verified by a request
  (task 02).
- On the iframe provider: this report is **authenticated by the external system**. Our locked
  params are passed through but are not enforced on the far side.

### Settings → Insights → Reports

One list, native and embedded together, in `sort_order`, with arrow reorder. Each row: label,
a source chip (`Built-in` / provider name), visibility, and a validation warning triangle when
`validation_status` is not `valid` (tooltip carries `validation_detail`).

System rows show the same controls except archive, which is disabled with a tooltip
explaining that built-in reports can be hidden but not removed.

### Report builder (drawer)

Step-shaped, but a single form:

1. **Source** — Built-in (pick from unused registry entries) or Embedded (pick an account).
2. **Resource** — when the account supports discovery, a searchable select of dashboards and
   questions with an `enabled_for_embedding` indicator; unpublished resources are selectable
   but flagged, so the operator learns *why* it will fail rather than not finding it. When
   discovery returns **409** with message `provider_not_discoverable` (adapter lacks
   `ListsResources` — e.g. iframe), do **not** treat that as an empty catalogue: fall back to
   kind select + free-text `resource_ref`. An empty **200** list means the instance has no
   resources; a **409** means discovery is unavailable.
3. **Parameters** — one row per provider-declared param:
   - slug + provider embedding mode, read-only
   - binding: **Locked** / **Editable filter**, disabled to Locked when the value is dynamic
   - value source: **Static** / **Dynamic** toggle, same treatment as `ValueSource` in
     automations
   - static → typed input matching the param type; dynamic → select of whitelisted keys,
     filtered by type compatibility
   - a required param with no binding blocks save
4. **Presentation** — label per locale (`en` / `es` / `fr` inputs), description, icon,
   section, `options` toggles (bordered, titled, downloads).
5. **Scope** — `inherit` / `ignore`, with plain-language help: *inherit* re-renders when the
   site selector changes; *ignore* is a company-wide report unaffected by it.
6. **Visibility** — enum select, with a note that only `All` is enforced today.

Save surfaces validation failures inline against the offending param row, with the
provider-side instruction from task 05 rendered verbatim. Do not collapse a param mismatch
into a generic toast — the whole point of task 05 is that the operator can act on it.

## Implementation notes

- Composables `useAnalyticsAccounts()`, `useAnalyticsAccount(id)`, `useInsightReports()`,
  `useInsightReport(id)`, `useAnalyticsResources(accountId, kind)` — `useXxx` / `useXxxList`
  convention, HTTP via `useApi()`.
- Types in `app/types/insights.ts`: `AnalyticsAccount`, `AnalyticsProviderDescriptor`,
  `InsightReport`, `InsightReportParam`, `DynamicParamKey`, `ValidationStatus`. Arrays as
  `Array<T>`.
- Reuse the automations `ValueSource` toggle component if it can be lifted without dragging in
  automation-specific types; otherwise copy the *visual* treatment and keep the types local.
  Do not import automation domain types into insights.
- The param editor is the one piece worth extracting: `InsightParamRow.vue`, used by the
  builder and (later) by any provider-specific editor.
- `canEdit`: gate these screens behind the **existing** helper. Do not extend the stopgap —
  invariant note from `10-open-decisions.md` applies, and S17 must remain a one-file change.

## i18n

Key namespaces `settings.insights.*`, `insights.params.*`, `insights.validation.*`.
Every string in `en.json`, `es.json`, `fr.json`; English fallback acceptable for `fr`.

Spanish matters most — first deploy. Suggested terms, to be reviewed by the client's
operator: *Conexiones*, *Informes*, `Locked` → *Fijado*, `Editable filter` → *Filtro
editable*, `Static` → *Estático*, `Dynamic` → *Dinámico*, `Built-in` → *Integrado*,
"Take out of service"-style destructive labels follow the existing conventions.

Dynamic param keys need human labels too (`insights.params.dynamic.current_site_id` → *Sitio
actual*). Ship those keys with the resolver registry so a new resolver cannot land without
its label.

## Invariants

- All shipped strings through i18n; **operator-authored report labels are data** (I4) and are
  the documented exception. The builder writes them to `labels`, never to a locale file.
- `Array<T>` typing; `useApi()` for HTTP; SPA only.
- Secrets never rendered raw; blank means unchanged.
- Arrow reorder before drag-and-drop, consistent with object customization.

## Acceptance criteria

- [ ] An operator can add a Metabase connection, verify it, and see a masked secret on reload.
- [ ] Submitting the form with a blank secret does not wipe the stored one.
- [ ] The credential form renders from the providers registry — adding a commented-out
      provider to the registry produces a working form with no Nuxt change (verify by
      temporarily enabling one).
- [ ] An operator can create a report from a question, bind one param to a static value and
      one to `current_site_id`, and save.
- [ ] Choosing Dynamic forces the binding control to Locked and disables it, with an
      explanatory tooltip.
- [ ] A param mismatch renders inline on the offending row with the provider-side instruction,
      not as a generic error.
- [ ] Native and embedded reports appear in one reorderable list; reordering persists and is
      reflected in the Insights nav.
- [ ] A built-in report can be hidden and relabelled but not archived.
- [ ] A `credentials_unreadable` account renders the re-enter state.
- [ ] `bun run lint` and `bun run typecheck` pass.
- [ ] `en.json`, `es.json`, `fr.json` all contain every new key, including one label per
      dynamic param key.

## Tests required

Panel has no test runner beyond lint/typecheck per `01-stack.md`, so verification is manual
plus the API coverage in tasks 02–05. Record this script in the PR description, run against a
seeded database and a live Metabase:

1. Add a connection with a deliberately wrong API key → red status, `last_error` shown,
   credentials still present.
2. Fix the key → verify → green.
3. Create a report from a **published** dashboard with `site_id` locked on the Metabase side →
   saves clean.
4. Create a report from the same dashboard but with `site_id` set to editable in Metabase →
   save refused, inline message names `site_id` and the required change.
5. Create a report against an **unpublished** dashboard → refused with `resource_missing`.
6. Bind `visible_site_ids` to a scalar param → refused at save.
7. Relabel a built-in report in Spanish only → the Spanish label shows for an English user
   (fallback), and the archive control stays disabled.
8. Reorder so an embedded report sits between two built-ins → nav order matches.
9. Stop the Metabase instance → save a new report → allowed with an `unreachable` warning
   triangle.
10. Set a second connection as default → the first loses its badge.
