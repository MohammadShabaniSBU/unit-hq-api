# S18-00 — Lock insights decisions

## Context

Six ambiguities in the insights design each cost a migration or a rewrite if resolved late.
All six touch code written in tasks 02–07. Resolve them now, in one pass, before any schema
work starts.

This task is documentation plus one enum decision. It is deliberately first.

## Scope

**In:**
- Decide and record I1–I6 below in `10-open-decisions.md`
- Decide the OSS-versus-paid Metabase question and record the commercial answer
- Add the new invariants to `09-conventions-and-invariants.md`

**Out:**
- Any schema change (tasks 01–03)
- Any adapter code (task 02)

## Decisions to record

### I1 — One registry, two sources

Native reports and embedded reports live in the same `insight_reports` table, distinguished
by `source`. The Insights nav is one ordered, operator-controlled list. There is no
"integrations" sub-menu and no second nav section.

**Rationale:** an operator who replaces our ageing report with their own should be able to
hide ours and put theirs in the same slot. Two lists make that impossible and make the nav
order a code change.

### I2 — Dynamic params are always locked

`value_source = 'dynamic'` implies `binding = 'locked'`. Enforced by a `CHECK` constraint,
not by application code alone. Only `static` params may be `default` (an editable filter
widget seeded with a value).

**Rationale:** dynamic values are site scope and viewer identity. An editable dynamic param
is a URL-editing privilege escalation. There is no legitimate case for one; if a viewer
should be able to change a value, it is not a scope value.

### I3 — Dynamic values come from a whitelist

Resolvers live in `App\Support\Insights\DynamicParams` and are addressed by a stable key.
The stored `dynamic_key` is validated against the registry on write and on read. No free
text, no expression language, no reuse of the automation `ValueSource` *expression* strings.

**Rationale:** same reasoning as `FilterableFields` — never trust a client-supplied
identifier that resolves to data access. The automation engine's dynamic expressions are
richer than we need and carry a much larger attack surface.

Note the deliberate half-borrow: we reuse the **`static` / `dynamic` vocabulary** from
`action.create_object` so the panel component and the operator's mental model are the same,
but **not** the expression grammar.

### I4 — Operator labels are per-locale JSONB; native labels are i18n keys

`insight_reports.labels` is `JSONB` keyed by locale, populated only for operator-created
reports. Native rows leave it `NULL` and resolve through `native_key` → `en.json` /
`es.json` / `fr.json`. One resolver handles both:

```
label = labels->>locale ?? labels->>'en' ?? t("insights.reports.{native_key}.label")
```

**Rationale:** operator data cannot go through the locale files (they are shipped assets),
and native strings must not be duplicated into the database (they would drift from the
translations). Trying to make one mechanism serve both produces either untranslatable
built-ins or unsyncable operator strings.

### I5 — `visibility` ships as an enum stub now

`insight_reports.visibility`: `all` | `company_only` | `site_staff`. Only `all` is honoured
this sprint; the others are accepted, stored, and ignored until RBAC lands (S17 /
`canEdit` stopgap). The column and enum exist now.

**Rationale:** exactly the D3 reasoning — adding enum values later means backfilling. A
column nobody reads costs nothing.

### I6 — Site scope is per-report, not global

`insight_reports.site_scope_mode`: `inherit` (default) | `ignore`.

- `inherit` — the global site selector feeds `current_site_id` / `visible_site_ids`, and the
  report re-renders when the selector changes.
- `ignore` — the report is rendered once, unscoped; the panel hides the site selector's
  effect on it and labels the report as company-wide.

**Rationale:** a customer's own corporate dashboard often has no concept of our sites.
Forcing `inherit` means either a broken filter or a lie in the UI.

### Commercial decision to record (not an engineering choice)

Metabase OSS shows a "Powered by Metabase" banner on embeds and does not allow appearance
customisation; both clear on a paid plan. Decide and write down:

- Do we ship OSS with the banner, or bundle a paid plan into our pricing?
- If a customer brings their own instance, is its plan our problem? (Recommended answer: no —
  we render whatever their instance returns and say so in the docs.)

**Do not attempt to hide the banner with CSS or iframe tricks.** It is a licence term.

## Schema changes

None in this task. The columns decided here are created in task 03.

## Implementation notes

- Write I1–I6 into `10-open-decisions.md` under **Decided (do not reopen)**, with the
  rationale text above, not a summary of it. The rationale is the part that stops a future
  session reopening the decision.
- The four new invariants go into `09-conventions-and-invariants.md` in task 08, not here —
  but draft them now so tasks 02–07 can quote them.

## API surface

None.

## Panel surface

None.

## Invariants

These are the ones this sprint will add (drafted here, appended in task 08).
Numbers continue after current invariant 48 in `09-conventions-and-invariants.md`:

> **49. Analytics provider credentials follow the shared credential rules.** Invariants 26
> and 27 apply unchanged to `analytics_accounts` — encrypted at rest, masked last-4 in
> responses, blank submitted field means unchanged, Tier-3 `RecordsActivity::core` on
> create / rotate / remove, `DecryptException` degrades to `credentials_unreadable`.

> **50. A dynamic insight param is always locked.** `value_source = 'dynamic'` implies
> `binding = 'locked'`, enforced by database constraint. Only static params may be editable
> defaults. Dynamic values resolve from the `DynamicParams` whitelist, never from
> client-supplied identifiers.

> **51. Embed tokens are minted server-side and short-lived.** The provider's signing secret
> never appears in an API response, a queue payload, an activity log, or the panel bundle.
> Token TTL is at most 10 minutes.

> **52. Insight reports and analytics accounts are archive-only.** `archived_at`;
> `POST …/archive` / `…/unarchive`; `DELETE` aliases archive. Consistent with sites,
> automations and attribute definitions.

## Acceptance criteria

- [x] `10-open-decisions.md` contains I1–I6 under "Decided", with rationale.
- [x] The commercial banner/licence answer is recorded in the same place.
- [x] Draft text for invariants 49–52 exists and is quoted by tasks 02–07.
- [x] No code changed in this task.

## Tests required

None — this task produces documentation only. Its output is verified by tasks 02–07
quoting it without contradiction.
