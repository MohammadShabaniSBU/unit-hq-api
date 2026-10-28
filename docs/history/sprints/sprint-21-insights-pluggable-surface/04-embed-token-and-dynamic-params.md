# S18-04 — Embed token endpoint and dynamic params

## Context

This is the security-critical task of the sprint. Everything else is CRUD.

The provider's signing key can mint a token for **any** published resource on that instance.
The locked params baked into a token are the only thing standing between a viewer and another
site's data. Both facts mean the same thing: token minting happens server-side, from stored
definitions, with values the viewer cannot influence.

If any part of this ends up in the panel, the feature is broken regardless of what the tests
say.

## Scope

**In:**
- `App\Support\Insights\DynamicParams` resolver whitelist
- `MetabaseProvider::embedUrl()` — JWT signing
- `IframeProvider::embedUrl()` — template substitution
- `POST /api/insights/{key}/embed`
- Tier-1 trace events for mint success/failure

**Out:**
- Validating that the provider published the param as locked (task 05)
- Panel refresh loop (task 07)

## Schema changes

None.

## Implementation notes

### Resolver whitelist (I3)

`App\Support\Insights\DynamicParams` — a static registry, same tier and shape as
`FilterableFields`. Each entry declares a key, a return type, and a closure taking the
request context (authenticated employee, selected site, locale):

| Key | Type | Resolves to |
|---|---|---|
| `current_site_id` | `int` | The global site selector's current site |
| `visible_site_ids` | `array<int>` | `SiteAccess::visibleSiteIds($employee)` |
| `current_employee_id` | `int` | Authenticated employee |
| `site_currency` | `string` | Selected site's currency (D1 — site wins) |
| `site_timezone` | `string` | Selected site's IANA timezone |
| `today` | `date` | Today **in the site's timezone**, not the server's |
| `month_start` / `month_end` | `date` | Current month bounds, site timezone |
| `year_start` | `date` | Current year start, site timezone |
| `locale` | `string` | Request locale |

The declared type is used by task 05 to reject wiring an `array` key into a scalar param at
save time. Store the type in the registry, not in the database — it is code, and a stored copy
would drift.

`current_site_id` on an "All sites" selection resolves to `null`. A report with a required
param bound to it must fail closed with a clear message ("this report requires a single site;
choose one"), never fall through to unscoped.

**Do not add a generic expression resolver.** Not now, not as a follow-up, not "just for
dates". The moment an operator can write an expression that reaches the ORM, the whitelist has
no meaning.

### `POST /api/insights/{key}/embed`

```
1. Resolve the report by key; 404 when archived or invisible to the caller.
2. Authorize: the same visibility/site helper used by GET /api/insights.
3. Native source → 400. Native reports do not mint tokens.
4. Account archived, or credentials_unreadable → 409 with a machine key the panel maps
   to a specific empty state.
5. Resolve every param:
     static  → static_value as-is
     dynamic → DynamicParams::resolve(dynamic_key, context)
   A required param resolving to null → 422 with the param name.
6. Split resolved params by binding: locked → signed into the token,
   default → appended as query parameters.
7. adapter->embedUrl(report, resolved) → URL.
8. Return { url, expires_at }.
```

Rate-limit per employee (a sane ceiling — one mint per report per minute is generous, since
the panel only re-mints on expiry or site change).

### Metabase token

HS256, signed with `embedding_secret_key`:

```php
$payload = [
    'resource' => [$report->resource_kind => $report->resource_ref],  // dashboard|question
    'params'   => $lockedParams,     // slug => value; array values allowed
    'exp'      => now()->addMinutes(10)->timestamp,
];

$url = rtrim($account->base_url, '/')
     . "/embed/{$report->resource_kind}/" . JWT::encode($payload, $secret, 'HS256')
     . '#' . http_build_query($report->options)      // bordered, titled, theme
     . $this->editableQuery($defaultParams);
```

Confirm the exact path segment for questions versus dashboards against the target instance
before shipping — it differs from the resource key in some versions, and a wrong guess here
produces a 404 that looks like a permissions problem.

TTL is 10 minutes, configurable **downward** only. `exp` is not optional: an unexpiring embed
URL is a shareable, unauthenticated link to the data.

### iframe token

Substitute `{param}` placeholders in `base_url` from resolved params, `rawurlencode` every
value, and reject the result if any placeholder is unfilled or if the final URL's host left
the allowlist. No secret, no expiry — and the panel must label these reports as
"externally authenticated" so nobody assumes our locked params are enforcing anything on the
far side. They are not. That is the honest limitation of a generic embed and it belongs in the
UI, not in a comment.

### Logging

Tier-1 `SystemEvent` on mint: `insights.embed.minted` / `insights.embed.failed`, with report
key, account id, resolved **param names** and failure reason. Never param values (they carry
site ids and employee ids, which is fine, but the habit of logging resolved values is how a
secret ends up in a log the first time someone adds a credential-shaped param). Never the
token.

No Tier-2 or Tier-3 activity for viewing a report — that is trace, not audit.

## API surface

```
POST /api/insights/{key}/embed
  → 200 { url, expires_at }
  → 400 report_is_native
  → 409 account_archived | credentials_unreadable | provider_not_embeddable
  → 422 param_unresolved (with param name), site_required
```

Error bodies use machine keys the panel translates, consistent with invariant 14's treatment
of activity descriptions.

## Panel surface

Nothing in this task. Task 07 consumes the endpoint.

## Invariants

> **31. Embed tokens are minted server-side and short-lived** (S18-00 draft) — the signing
> secret never appears in an API response, a queue payload, an activity log, a system event,
> an exception message, or the panel bundle. TTL ≤ 10 minutes.

> **30. A dynamic insight param is always locked** — this task is where that matters. A
> dynamic value that reached the query string instead of the token would be viewer-editable.

> **16. Manual activity rows go through `RecordsActivity`** with a `LogChannel` — and Tier-1
> trace rows go through `SystemEvent::record`, never bare logging.

Also invariant 1 (mono-tenant): `visible_site_ids` is a *scope* value for an external report,
not a tenancy boundary. It must never grow into a global query filter on our side.

## Acceptance criteria

- [ ] A minted Metabase URL decodes to a JWT whose `params` contain every locked param and
      whose `exp` is ≤ 10 minutes out.
- [ ] Dynamic params never appear in the URL's query string — assert by parsing the returned
      URL.
- [ ] Changing the site selector and re-minting produces a different `site_id` in the token.
- [ ] A required param that resolves to null returns 422 naming the param, and mints nothing.
- [ ] "All sites" + a report requiring `current_site_id` returns `site_required`, not an
      unscoped token.
- [ ] `today` resolves in the **site's** timezone — assert with a site whose timezone crosses
      the date boundary relative to the server.
- [ ] An unknown `dynamic_key` (e.g. injected directly into the database) fails closed with a
      500-free error and a trace event, rather than resolving to null.
- [ ] A native report returns 400 from this endpoint.
- [ ] An archived account returns 409 with a machine key.
- [ ] The signing secret appears in no response body, no `system_events` payload, and no
      `activity_log` properties — assert by scanning both tables after a mint.
- [ ] The iframe provider rejects an unfilled placeholder and a host that left the allowlist.

## Tests required

| Test | Asserts |
|---|---|
| `EmbedTokenTest::locked_params_are_signed_not_queried` | The core security property |
| `EmbedTokenTest::token_expires_within_ttl` | `exp` present and bounded |
| `EmbedTokenTest::site_selector_changes_token_scope` | Re-mint reflects new scope |
| `EmbedTokenTest::unresolved_required_param_returns_422` | Named param, no token |
| `EmbedTokenTest::all_sites_with_single_site_report_fails_closed` | No unscoped fallback |
| `EmbedTokenTest::native_report_rejected` | 400 |
| `EmbedTokenTest::archived_account_returns_conflict` | 409 machine key |
| `EmbedTokenTest::secret_never_appears_in_logs_or_response` | Scans both log tables |
| `DynamicParamsTest::unknown_key_fails_closed` | No silent null |
| `DynamicParamsTest::today_uses_site_timezone` | Boundary-crossing site |
| `DynamicParamsTest::visible_site_ids_matches_site_access` | Single source of scope |
| `IframeProviderTest::rejects_unfilled_placeholder` | 422 |
