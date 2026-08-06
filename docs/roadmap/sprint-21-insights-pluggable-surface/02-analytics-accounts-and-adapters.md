# S18-02 — `analytics_accounts` and provider adapters

## Context

An operator needs to tell us where their analytics instance lives and how to authenticate to
it. We already solved this problem once for messaging providers. `communication_accounts`
holds encrypted per-provider credentials, adapters declare capability by implementing
interfaces rather than returning a boolean map, and a resolver picks the live one.

Do the same thing. The differences are small and specific: there is no channel dimension, the
scope is company-only (analytics is not per-site), and one account is the default rather than
one being active per channel.

## Scope

**In:**
- `analytics_accounts` table
- `AnalyticsProvider` / `SignsEmbedTokens` / `ListsResources` / `DescribesResourceParams`
  interfaces + registry
- Metabase adapter: verify + credential fields
- Generic `iframe` adapter (URL template, no credentials)
- Connection verification endpoint
- Credential masking, blank-means-unchanged, Tier-3 audit

**Out:**
- Token minting (task 04 — the interface is declared here, implemented there)
- Resource listing implementation (task 05 — same)
- Any panel UI (task 06)
- Superset / Power BI adapters (registry entries left commented, exactly as Mandrill and
  Sinch are in `06-communications.md`)

## Schema changes

```sql
CREATE TABLE analytics_accounts (
    id                BIGSERIAL PRIMARY KEY,
    provider          VARCHAR(32) NOT NULL,   -- metabase | iframe
    display_name      VARCHAR(128) NOT NULL,
    base_url          VARCHAR(255) NOT NULL,
    credentials       TEXT NOT NULL,          -- encrypted:array
    is_default        BOOLEAN NOT NULL DEFAULT false,
    connection_status VARCHAR(24) NOT NULL DEFAULT 'pending',  -- pending|connected|error
    last_error        TEXT NULL,
    last_verified_at  TIMESTAMP NULL,
    archived_at       TIMESTAMP NULL,
    created_by        BIGINT NULL REFERENCES employees(id),
    created_at        TIMESTAMP,
    updated_at        TIMESTAMP
);

CREATE UNIQUE INDEX analytics_accounts_default_idx
    ON analytics_accounts (is_default)
    WHERE is_default AND archived_at IS NULL;
```

The partial unique index mirrors `tax_rates.is_default` and the one-active-account-per-channel
index in comms. At most one default; archived rows are exempt so archiving does not require
clearing the flag first.

`base_url` is a separate column rather than a credential field because it is not a secret,
it is displayed everywhere, and task 05 needs it to build API calls without decrypting.

## Implementation notes

### Interfaces (capability by presence, never a `capabilities()` map)

`App\Support\Insights\Contracts\`:

| Interface | Methods | Purpose |
|---|---|---|
| `AnalyticsProvider` | `make`, `verify`, `credentialFields`, `resourceKinds` | Every adapter |
| `SignsEmbedTokens` | `embedUrl(InsightReport $r, array $resolvedParams): string` | Task 04 |
| `ListsResources` | `resources(string $kind): array` | Task 05 |
| `DescribesResourceParams` | `resourceParams(string $kind, string $ref): array` | Task 05 |

The panel enables the "browse dashboards" picker only when `instanceof ListsResources`, and
falls back to a free-text resource id field otherwise — the same derivation the comms panel
uses for `auto_registers_webhooks`. There is no column recording this.

### Adapters

**Metabase** (`App\Support\Insights\Providers\MetabaseProvider`) implements all four.

Credential fields:

| Field | Secret | Purpose |
|---|---|---|
| `embedding_secret_key` | yes | HS256 signing key for embed tokens (task 04) |
| `api_key` | yes | Reading dashboards, cards and their params (task 05) |

`verify()` calls the instance's current-user endpoint with the API key header and treats a
2xx as connected. Do **not** verify against the unauthenticated health endpoint — it proves
the host is up, not that the key works, and a green tick on a bad key is worse than a red
cross. Confirm the exact endpoint and header name against the running instance's API docs
before writing the call; do not take this file's word for it.

`embedding_secret_key` cannot be verified by a request — it is only exercised when a token is
minted. Record `connection_status = 'connected'` on API-key success and surface the signing
key's validity through the first successful report render instead. Say this in the panel copy
so a green connection tick is not read as "embedding works".

**iframe** (`IframeProvider`) implements `AnalyticsProvider` + `SignsEmbedTokens` only.

- No credentials. `credentials` stores `{}` (still encrypted; the column stays `NOT NULL`).
- `base_url` is a URL template containing `{param}` placeholders.
- `verify()` performs a `HEAD` against `base_url` with placeholders stripped and reports
  reachability, clearly labelled as reachability rather than authentication.
- **Must reject non-`https` templates and must reject a template whose host is not in an
  operator-managed allowlist.** An unvalidated URL template rendered in an iframe inside an
  authenticated panel is an invitation.

Registry entries for `superset` and `powerbi` are added commented out.

### Credential handling — reuse, do not reimplement

`App\Support\Credentials\` already has all of it:

- `CredentialMasker` — masked `••••••` + last 4, `DecryptException` →
  `credentials_unreadable: true` in the resource instead of a 500.
- `CredentialField` — blank submitted field means unchanged. Never wipe a connected account.
- `CredentialAudit` — Tier-3 `RecordsActivity::core` on create / rotate / remove, properties
  limited to provider, account id, masked last-4 and result. **Never the secret.**

If you write a new masker here, you have made a mistake.

### Archive, never delete

`DELETE /api/settings/analytics-accounts/{id}` sets `archived_at`. Reports pointing at an
archived account keep their rows and render an "account archived" empty state (task 07).
Archiving is refused while the account is `is_default` and any non-archived report references
it — force the operator to repoint or archive the reports first, the same way a site cannot be
archived under active contracts.

## API surface

```
GET    /api/settings/analytics-accounts              masked credentials, never raw
POST   /api/settings/analytics-accounts              { provider, display_name, base_url, credentials }
PATCH  /api/settings/analytics-accounts/{id}         blank credential field = unchanged
POST   /api/settings/analytics-accounts/{id}/verify  → { status, last_error?, last_verified_at }
POST   /api/settings/analytics-accounts/{id}/default
POST   /api/settings/analytics-accounts/{id}/archive
POST   /api/settings/analytics-accounts/{id}/unarchive
DELETE /api/settings/analytics-accounts/{id}         aliases archive
GET    /api/settings/analytics-providers             registry: { key, label, credential_fields,
                                                       resource_kinds, lists_resources,
                                                       describes_params }
```

Response shape via `ApiResponsable` — `{ message, data }`, paginated `{ meta }`.

`GET /api/settings/analytics-providers` is what lets the panel render a credential form per
provider without hardcoding field lists in Nuxt. `lists_resources` and `describes_params` are
derived from `instanceof`, computed per request, never stored.

## Panel surface

Nothing in this task. Task 06 handles the settings screen.

## Invariants

> **26. Credentials are encrypted at rest and never returned in API responses** — secrets
> serialize as masked last-4; a blank submitted field means unchanged, never wipe.

> **27. Credential lifecycle events are Tier-3 `RecordsActivity::core`** — create / rotate /
> remove only; properties limited to identifiers, masked last-4, and result.

> **31. Embed tokens are minted server-side and short-lived** (S18-00 draft) — this task
> stores the signing key and must never expose it. `embedding_secret_key` appears in no
> response, no log, no activity property, no exception message.

> **32. Insight reports and analytics accounts are archive-only** (S18-00 draft).

Also: no `app/Services/` layer. Adapters live under `App\Support\Insights\`, same tier as
`App\Support\Communications\Senders\` and `App\Support\Billing\`.

## Acceptance criteria

- [ ] Migration runs on SQLite and Postgres.
- [ ] Creating a Metabase account with a valid API key sets `connection_status = connected`
      and `last_verified_at`.
- [ ] An invalid API key sets `status = error` with `last_error` populated, **and the
      credentials are still stored** so the operator can see and fix them.
- [ ] `GET` never returns a raw secret — assert the response body does not contain the
      submitted value, for every credential field.
- [ ] `PATCH` with a blank `embedding_secret_key` leaves the stored key unchanged.
- [ ] A corrupted encrypted payload yields `credentials_unreadable: true`, not a 500.
- [ ] Setting a second account as default clears the first; the partial index holds.
- [ ] `DELETE` sets `archived_at` and the row survives; archiving a default account with live
      reports is refused with a translatable error key.
- [ ] The `iframe` provider rejects an `http://` template and a non-allowlisted host.
- [ ] `GET /api/settings/analytics-providers` reports `lists_resources: true` for metabase and
      `false` for iframe, derived from `instanceof`.
- [ ] Create / rotate / remove each produce exactly one Tier-3 activity row containing no
      secret material.

## Tests required

| Test | Asserts |
|---|---|
| `AnalyticsAccountTest::verify_marks_connected` | Happy path against a faked HTTP client |
| `AnalyticsAccountTest::verify_failure_keeps_credentials` | `status=error`, key still stored |
| `AnalyticsAccountTest::secrets_never_returned` | Every field masked, raw value absent |
| `AnalyticsAccountTest::blank_field_means_unchanged` | No wipe on partial update |
| `AnalyticsAccountTest::decrypt_exception_degrades` | `credentials_unreadable`, not 500 |
| `AnalyticsAccountTest::single_default_enforced` | Partial unique index |
| `AnalyticsAccountTest::delete_archives_not_deletes` | Row count unchanged |
| `AnalyticsAccountTest::archive_refused_with_live_reports` | 422 with translatable key |
| `AnalyticsAccountTest::credential_events_are_tier_three` | One `core` row, no secret in props |
| `IframeProviderTest::rejects_insecure_and_unlisted_hosts` | 422 on both |
| `ProviderRegistryTest::capabilities_derived_from_interfaces` | No stored capability flags |
