# S32-01 — Ad platform accounts & adapters

## Layout

```
app/Support/Advertising/
  AdPlatform.php                    -- enum (00)
  AdPlatformRegistry.php            -- provider → adapter class, like ProviderRegistry
  Contracts/
    AdPlatformAccount.php           -- make(credentials), verify(): AccountInfo, credentialFields()
    ReportsConversions.php          -- deliver(array<ConversionPayload>): DeliveryResult[]
    ListsCampaigns.php              -- campaigns(): iterable<CampaignRow>
    ReportsSpend.php                -- spend(CarbonImmutable $from, CarbonImmutable $to): iterable<SpendRow>
    CapturesClickIds.php            -- clickIdKeys(): list<string>   (used by 02 to derive touches.platform)
  Providers/
    GoogleAds/GoogleAdsAccount.php  -- implements all four
    Meta/MetaAccount.php            -- implements all four
  Results/ AccountInfo, CampaignRow, SpendRow, DeliveryResult
  Hashing.php                       -- normaliseEmail, normalisePhoneE164, sha256 (02, 04)
```

Capability is interface presence. Like comms, never expose a `capabilities()` map.
The panel reads derived booleans (`reports_conversions`, `reports_spend`) from the
account resource.

## Credentials

Use the `encrypted` cast on `ad_platform_accounts.credentials`, with
`CredentialMasker` / `CredentialField` / `CredentialAudit` from
`App\Support\Credentials` (invariants 26–27). `DecryptException` →
`credentials_unreadable` on the resource, never a 500.

| Provider | Stored credential fields | Non-secret config |
|---|---|---|
| `google_ads` | `refresh_token` | `external_account_id`, `login_customer_id` |
| `meta` | `system_user_token`, `capi_token` | `external_account_id`, `pixel_id` (in credentials JSON, not masked) |

The Google **developer token**, OAuth client id and client secret are
**deployment config** (`config/advertising.php`, env), not operator data. They
belong to Keevaris.

## Google OAuth flow

1. `POST /api/ad-platform-accounts/google/oauth/start` (`advertising.connect`) →
   returns the Google consent URL with a signed, single-use `state` (cache, 10
   min, bound to employee id).
2. `GET /api/ad-platform-accounts/google/oauth/callback` (public route, allowlisted
   per invariant 42) → validates `state`, exchanges the code, and lists accessible
   customers (`CustomerService.ListAccessibleCustomers`). It redirects to the
   panel with a short-lived selection token.
3. `POST /api/ad-platform-accounts/google/oauth/complete` → the operator picks the
   customer (and MCC, if any). We verify and store the account.

Verify reads `customer.currency_code` and `customer.time_zone` into
`currency` / `timezone`. The operator never types them.

## Meta connection

Plain credential form (`POST /api/ad-platform-accounts` with `provider = meta`).
Verify calls `GET /act_{id}?fields=currency,timezone_name,account_status` with the
system-user token, and `GET /{pixel_id}?fields=id,name` with the CAPI token. Both
must succeed before the row saves.

## API

| Method | Path | Notes |
|---|---|---|
| `GET` | `ad-platform-accounts` | Masked credentials, derived capability booleans, `last_verified_at`, `last_error` |
| `POST` | `ad-platform-accounts` | Meta (Google uses the OAuth endpoints) |
| `PUT` | `ad-platform-accounts/{id}` | Name, rotate secrets (blank = unchanged), toggle `is_active` |
| `POST` | `ad-platform-accounts/{id}/verify` | Re-runs verify, updates currency/timezone/last_error |
| `POST` | `ad-platform-accounts/{id}/archive` | `DELETE` aliases archive. Archived accounts stop syncing and delivering, and history stays. |

## HTTP clients

- Google: REST (`googleads.googleapis.com`, pinned API version in config), not the
  gRPC PHP client. That avoids the gRPC extension in the Docker image. Wrap
  `searchStream` and `uploadClickConversions`.
- Meta: Graph API REST, version pinned in config.
- Both go through Laravel `Http` with timeouts and retry on 429/5xx with backoff.
  Errors are normalised to `AdPlatformException` with `retryable: bool`.

## Activity & system events

- Connect / rotate / remove: Tier-3 via `CredentialAudit` (never the secret).
- Verify failure: Tier-1 SystemEvent `ads.account.verify_failed`.

## Acceptance criteria

- [ ] Google OAuth round trip works against a test account. A replayed `state`
      is rejected.
- [ ] A Meta account with a valid ad-account token but a wrong pixel token is
      refused on save, with a field-level error.
- [ ] Secrets never appear in API responses, logs, activity properties or
      Telescope (redact the `credentials` attribute in Telescope's hidden list).
- [ ] An archived account is skipped by sync and delivery jobs (tests in 04/05).
- [ ] HTTP fakes cover both adapters. No test touches the network.
