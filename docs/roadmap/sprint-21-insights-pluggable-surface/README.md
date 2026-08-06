# Sprint 18 — Insights as a pluggable surface

> **Numbering.** The roadmap in `docs/roadmap/README.md` ends at S17. This sprint is
> additive and has no hard dependency on S08–S17, only on the reports that already exist.
> If you slot it earlier, renumber the directory and leave the task numbers alone.

## Goal

Turn **Insights** from a fixed set of hard-coded pages into a **registry**. Reports come
from two sources — the app's own native pages, and dashboards or questions embedded from an
analytics provider — and both are defined as data, reorderable and hideable from Settings.
Metabase is the default provider; the adapter interface exists so a customer who already
runs Superset, Looker Studio or Power BI can point Insights at their own instance.

The shape is deliberately the **same as `communication_accounts`**: a provider-agnostic
credential row, capability-by-interface adapters, encrypted credentials, masked responses,
Tier-3 audit on create/rotate/remove. If you find yourself inventing a new pattern here,
you have gone wrong — go read `06-communications.md` again.

## Why now

1. **Report requests are unbounded.** Every customer wants a slightly different cut. Today
   each one is a Nuxt page, an API endpoint, a query, and three locale files. That does not
   scale past the second customer.
2. **Some customers already have analytics.** A prospect running their own BI stack should
   not be told to abandon it or to leave the product to look at a chart.
3. **The native reports are not going away.** Rent roll, ageing, daily close and deposit
   liability are operational documents with fiscal weight. They stay in the app. What
   changes is that they become rows in the same registry as everything else, so the nav is
   one ordered list rather than two.

## Exit criteria

- [ ] An operator can connect a Metabase instance from Settings → Insights, see the
      connection verified, and see a masked credential afterwards — never a raw secret.
- [ ] An operator can create a report from *question 2* on that instance, bind `site_id` to
      the dynamic value `current_site_id` and `charge_type` to a static value, save it, and
      see it appear in the Insights nav.
- [ ] Opening that report renders the embed scoped to the selected site, with no way for the
      viewer to widen the scope by editing the URL.
- [ ] The twelve existing native reports appear in the same registry, can be reordered and
      hidden, and still render their native components.
- [ ] A report pointed at a generic `iframe` provider renders, proving the adapter layer is
      not Metabase-shaped.
- [ ] A default Metabase install against a seeded database can build a dashboard from the
      `analytics` schema without being granted access to any business table.
- [ ] Saving a report whose provider-side parameter is not published as locked is refused at
      save time with an actionable message, not discovered as a blank iframe.

## Task order

Strictly sequential. Tasks 01–02 are independent of each other but both precede 03.

| # | Task | Est. |
|---|---|---|
| 00 | [Lock insights decisions](./00-lock-insights-decisions.md) | 0.5 day |
| 01 | [`analytics` read schema and reporting role](./01-analytics-read-schema.md) | 1 day |
| 02 | [`analytics_accounts` and provider adapters](./02-analytics-accounts-and-adapters.md) | 1 day |
| 03 | [`insight_reports` registry and params](./03-insight-reports-registry.md) | 1 day |
| 04 | [Embed token endpoint and dynamic params](./04-embed-token-and-dynamic-params.md) | 0.75 day |
| 05 | [Resource discovery and save-time validation](./05-resource-discovery-and-validation.md) | 0.75 day |
| 06 | [Panel: Settings → Insights](./06-panel-settings-insights.md) | 1 day |
| 07 | [Panel: registry-driven Insights surface](./07-panel-insights-surface.md) | 1 day |
| 08 | [Doc realignment: insights and invariants](./08-doc-realignment-insights.md) | 0.25 day |

Roughly 7.25 days. **This sprint is full.** Task 01 is the split candidate — it is the only
task with no dependency on the others and can move to its own half-sprint without blocking
anything else. Do not split tasks 03–05; they are one design.

## Risks

**The embedding secret is a total-compromise key.** Whoever holds it can mint a token for
any published resource on that instance. It never leaves the API, never appears in a
response, never reaches the panel bundle. Every embed URL is minted server-side with a short
expiry. Task 04 exists mostly to get this right; treat a leak here as severity-1.

**Locked parameters are the entire authorization story.** A dynamic param that a viewer can
edit is a privilege escalation — change `site_id` in the URL, see another site. The
`CHECK` constraint in task 03 and the resolver whitelist in task 04 are both load-bearing.
Do not relax either for convenience.

**Provider-side state is not ours.** A dashboard can be unpublished, deleted, or have its
embedding params changed in Metabase after we saved a report against it. Task 05's
validation runs at save time; task 07 must still degrade to a readable error state at view
time rather than an empty frame. Assume the remote resource is gone and design the empty
state first.

**Operator-authored strings cannot go through `en.json`.** Report labels created by an
operator are data, not i18n keys. Native report labels are i18n keys, not data. Task 03
defines one resolver that handles both; if two resolvers appear, the nav will disagree with
the page title in Spanish and nobody will notice for a month.

**OSS Metabase stamps a "Powered by Metabase" banner on embeds** and does not allow
appearance customisation. That is a commercial decision, not an engineering one — record the
answer in task 00 so the panel does not try to hide it with CSS.
