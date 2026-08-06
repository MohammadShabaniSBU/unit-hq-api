# S18-07 — Panel: registry-driven Insights surface

## Context

The Insights nav is currently twelve hardcoded route entries and twelve pages. This task
replaces the nav with the registry feed and adds one dynamic page that renders either a
registered native component or an embed, chosen by the report's `source`.

The native pages themselves do not change. Their routes move behind the registry, their
components stay exactly as they are.

## Scope

**In:**
- Nav built from `GET /api/insights`
- `/insights/[key]` dynamic route resolving native component or embed
- Native component registry mirroring `NativeReports`
- Embed frame with token refresh, site-selector reactivity, and complete error states
- Removal of the hardcoded nav list

**Out:**
- Changes to native report internals
- Cross-report filtering, favourites, dashboards-of-dashboards

## Panel surface

### Nav

`INSIGHTS` renders from the feed: label (resolved per I4 — either a string or an i18n key plus
flag), icon, in `sort_order`, grouped by `section` when present. Loading renders the previous
list from Pinia rather than collapsing the section — a nav that flickers empty on every route
change reads as a bug.

An embedded report whose account is not `connected` still appears, with a muted state. Hiding
it would leave an operator wondering where their report went; the error belongs on the page.

### `/insights/[key]`

```
resolve report by key from the Pinia registry (fetch if cold)
  ├── not found / archived → 404 state with a link back to Insights
  ├── source = native   → mount NativeReports[native_key], unchanged
  └── source = embedded → mount InsightEmbed
```

Native components are registered in `app/insights/registry.ts` keyed by `native_key`, mirroring
the API-side `NativeReports`. A `native_key` with no component renders a clear "report not
available in this build" state — that is the version-skew case and it must not be a white
screen.

### `InsightEmbed`

1. `POST /api/insights/{key}/embed` → `{ url, expires_at }`
2. Render an iframe with a skeleton until `load`
3. Re-mint at `expires_at` minus 60s while the page is visible; pause the timer when the tab
   is hidden and re-mint on focus if expired
4. Re-mint when the global site selector changes **and** `site_scope_mode = 'inherit'`
5. Never re-mint on an unrelated Pinia change — a token request per keystroke elsewhere is a
   rate-limit incident waiting to happen

Error states, each distinct and each translatable (mapping the machine keys from task 04):

| Condition | State |
|---|---|
| `409 account_archived` | "This report's connection was archived" + link to Settings |
| `409 credentials_unreadable` | "Re-enter the connection credentials" + link |
| `422 site_required` | "Choose a single site to view this report" + focus the selector |
| `422 param_unresolved` | Generic config error + link to the report's settings |
| `validation_status ≠ valid` | Warning banner above the frame, report still attempts to render |
| iframe fails to load | Timeout state after ~15s with a retry action |

That last one matters: an iframe pointing at an unreachable host fires no error event, it just
sits blank. Without a timeout the operator stares at a white rectangle. Set one.

For `iframe`-provider reports, show a persistent small note that the report is authenticated by
the external system — the same honesty requirement as task 06.

Respect `options`: `bordered`, `titled`, downloads. Do not attempt to restyle the embed's
interior, and **do not hide the provider's branding** (S18-00 commercial decision).

## Implementation notes

- Composables: `useInsightRegistry()` (Pinia-backed nav feed, refreshed on settings mutation)
  and `useInsightEmbed(key)` (mint, refresh timer, error mapping).
- Types added to `app/types/insights.ts`; arrays as `Array<T>`.
- The mint timer belongs in the composable, not the component, so unmount cancels it. A leaked
  interval re-minting after navigation is the most likely bug in this task.
- The iframe needs `sandbox` set to the minimum that still works for the provider
  (`allow-scripts allow-same-origin allow-popups` is typical for Metabase; verify) and a
  `referrerpolicy`. Do not use `allow-top-navigation`.
- Delete the hardcoded nav array in the same PR. A registry plus a leftover static list means
  two orders that disagree, and the static one will win somewhere.
- The site selector is already global; subscribe to it rather than re-reading route state.

## API surface

Consumes only: `GET /api/insights`, `POST /api/insights/{key}/embed`. No new endpoints.

## Invariants

- All shipped strings through i18n (`insights.embed.*`, `insights.states.*`); operator labels
  are data (I4).
- `Array<T>`, `useApi()`, SPA only.
- **Invariant 31** — the panel never holds a signing secret and never constructs an embed URL.
  It receives a URL and renders it. If any part of URL construction appears in Nuxt, reject the
  PR.
- `canEdit` stopgap: viewing is gated by the feed's visibility filter server-side; do not add a
  second client-side authorization rule.

## Acceptance criteria

- [ ] The Insights nav renders entirely from `GET /api/insights`; the hardcoded array is gone.
- [ ] All twelve native reports render unchanged through `/insights/[key]`.
- [ ] Reordering in Settings changes the nav order without a rebuild.
- [ ] Hiding a report removes it from the nav and makes its URL 404.
- [ ] An embedded Metabase report renders, scoped to the selected site.
- [ ] Switching sites re-mints and the frame reloads with the new scope; a report with
      `site_scope_mode = ignore` does **not** re-mint.
- [ ] The token refreshes before expiry on a page left open for 15 minutes, with no visible
      interruption.
- [ ] Navigating away cancels the refresh timer — assert no further mint requests in the
      network log.
- [ ] Each error state from the table renders distinctly and translatably.
- [ ] An unreachable embed host produces the timeout state with retry, not a permanent blank
      frame.
- [ ] `bun run lint` and `bun run typecheck` pass.
- [ ] `en.json`, `es.json`, `fr.json` complete.

## Tests required

Manual script for the PR description, run against the seeded database from S01-03 and a live
Metabase:

1. Every native report opens from the new nav and matches its pre-sprint output exactly —
   compare the headline figures on rent roll, ageing and daily close side by side with the
   previous build.
2. Create an embedded report scoped to `current_site_id`; switch sites; confirm the figures
   change and the token differs (check the network tab).
3. Set `site_scope_mode = ignore`; switch sites; confirm no re-mint.
4. Leave a report open 15 minutes; confirm one silent re-mint and no interruption.
5. Navigate away mid-session; confirm mint requests stop.
6. Archive the connection; reload the report; confirm the archived state with a Settings link.
7. Corrupt the stored credentials (rotate `APP_KEY` in a scratch environment); confirm the
   re-enter state, not a 500.
8. Point an `iframe` report at an unreachable host; confirm the 15s timeout state with retry.
9. Hide a report; confirm the nav drops it and the direct URL 404s.
10. Reorder so an embedded report is first; confirm nav order and that the Insights landing
    page still opens the dashboard report, not whatever is first.

If a component test setup is added later, `InsightEmbed`'s refresh timer is the first
candidate — it is the only piece here with non-trivial logic.
