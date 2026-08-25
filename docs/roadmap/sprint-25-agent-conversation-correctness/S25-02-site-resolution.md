# S25-02 — Site resolution: catchment areas, `facility.find_sites`, channel context

**Depends on:** S25-00
**Touches:** `unit-hq-api`, `unit-hq-panel`
**Trace evidence:** trace-13

## Problem

The customer wrote *"we are in 28001"* — a valid Madrid postcode. The agent
called `facility.site_info` with no arguments and got back
`site_id is required when no site is in context.` It surfaced that to the
customer as *"I don't have a site automatically linked to that postcode on my
end"* and offered to hand off to a human. The only seeded site is Madrid
Centro, postcode 28004, roughly a kilometre away.

Three separate defects sit underneath that one dead end.

**(a) Available site context was never used.** A real inbound SMS or WhatsApp
arrives on a number owned by a `site_sender_identities` row, which names a site.
That *is* the site. The demo pane has no inbound sender identity, which is why
this bit here and would not have on live traffic — but the fallback path has to
work regardless, because company-scoped senders and web chat have no site
either.

**(b) Nothing resolves a location to a site.** There is no tool for it and
`site_id` was mandatory, so the agent had no move available.

**(c) The error offered no recovery.** Addressed generally in S25-00; this task
supplies the tool that recovery points at.

## What to build

### Schema

```sql
-- sites: coordinates for distance fallback
alter table sites add column latitude  numeric(9,6) null;
alter table sites add column longitude numeric(9,6) null;

-- operator-declared catchment
create table site_service_areas (
    id            bigserial primary key,
    site_id       bigint not null references sites,
    kind          varchar not null,   -- postcode | postcode_prefix | admin_region
    value         varchar not null,
    archived_at   timestamp null,
    created_at, updated_at
);
-- partial unique (site_id, kind, value) where archived_at is null
-- index (kind, value) where archived_at is null
```

Archive-only, per the discipline in invariant 28 — an archived catchment row
stays resolvable for historical explanation of why a lead was routed somewhere.

**Why a catchment table rather than pure distance.** Operators know their
catchment better than a haversine does: a site across a river or on the wrong
side of a toll road is not served, and a site 40 km out on a motorway is. This
table also pays for itself the moment the online rental funnel exists, which
needs exactly the same question answered for a website visitor.

### `App\Support\Facility\SiteResolver`

```php
SiteResolver::resolve(?string $query, ?float $lat, ?float $lng, int $limit = 5): array
```

Ladder, first hit wins, always over `active()` sites only:

| # | Rule | `match_reason` |
|---|---|---|
| 1 | Exactly one active site exists | `only_site` |
| 2 | `site_service_areas` exact postcode | `service_area` |
| 3 | `site_service_areas` postcode prefix, longest match | `service_area_prefix` |
| 4 | `sites.postal_code` exact | `site_postcode` |
| 5 | City / `state_region` case-insensitive match | `locality` |
| 6 | Haversine over sites with coordinates | `distance` |
| 7 | No match → **all active sites** | `no_match` |

Step 7 is the point of the whole task. A resolver that can return nothing
reproduces the dead end. With no match the agent presents the list and asks —
it never escalates for want of a postcode.

Distance is computed in PHP over the bounded set of active sites with
coordinates. **No SQL geo functions**: SQLite is the local and test database and
has none, and the site count makes the optimisation pointless.

### Tools

- **`facility.find_sites(query?, latitude?, longitude?, limit = 5)`** →
  `Array<{site_id, name, address, city, postal_code, distance_km?, match_reason}>`,
  each also emitted as an `EntityRef` of type `site` so it licenses downstream
  calls (S25-01).
- **`facility.site_info`**: `site_id` becomes **optional**. Absent → resolver
  with no query. Single active site returns it; otherwise `ToolError`
  `site_unresolved` carrying `candidates` and
  `recovery.tool = 'facility.find_sites'`.

### Conversation site context

At conversation create, seed `context.site_id` from the inbound
`site_sender_identities` row when the channel has one. Company-scoped senders
and web chat leave it null and fall through to the resolver. A context-derived
site is a provenance licence under S25-01 source (3).

### Geocoding

`sites.latitude` / `longitude` are nullable and backfilled by a
`sites:geocode` command against whichever provider is configured. The resolver
skips uncoordinated sites at step 6. **No hard dependency on a geocoding
provider** — steps 1–5 and 7 work without one, and steps 2–5 are the ones that
actually fire in Spain.

### Panel

Settings → Facility → site detail gains a service-areas editor: add/archive
rows by kind, with a validation hint that postcode prefixes are matched longest
first. i18n keys under `facility.service_areas.*`.

## Acceptance criteria

- [x] With only Madrid Centro seeded, query `28001` **without a catchment row**
      returns that site as `no_match` (the model must ask, not assert). Task-file
      wording that this went via `only_site` is superseded: `only_site` fires only
      when query and coordinates are both absent. `28001` via `service_area_prefix`
      still holds once a `280` prefix row exists with a second site present.
- [x] Zero-match returns every active site with `match_reason: no_match`; an
      eval fixture (`sales/zero-match-offers-choice`) shows the agent offering a
      choice rather than escalating.
- [x] Archived sites never appear in any result.
- [x] `facility.site_info` with no arguments and one active site returns it.
- [x] `facility.site_info` with no arguments and three active sites returns
      `site_unresolved` with three candidates.
- [x] Inbound message on a site-scoped sender identity seeds conversation site
      context (operator-owned destination, never customer From); `site_info`
      with empty args then succeeds. Identity vs account disagreement prefers
      the identity and logs `ai.inbound.site_disagreement`.
- [x] Resolver runs identically on SQLite and PostgreSQL (test asserts both).

## Follow-on from S25-01

Live `--record` of the unlicensed-class recovery scenario (`agent:replay --live --record` against a fixture where the model passes a `unit_class_id` that was never returned, receives `unlicensed_argument`, calls `facility.availability` without that filter, then quotes a returned class). S25-01 left this as an authored cassette because descriptions did not change; a recording here replaces that fixture with observed behaviour. After retargeting, no recording in the suite exercises a successful availability→quote path.

## Out of scope

- Public (unauthenticated) availability endpoints for a website funnel.
- Routing/drive-time. Straight-line distance is the fallback, and the catchment
  table is the real answer.
