# S32-04 — Conversion outbox & delivery

## Event emission

One writer: `App\Support\Advertising\ConversionOutbox::record(ConversionEventKey, Model $subject, Contact $contact, ?Site $site, ?string $value, ?string $currency, CarbonImmutable $occurredAt)`.

It inserts an `ad_conversion_events` row **only if at least one active account has
an active mapping for that event key**, so installs without ads write nothing. It
uses `ON CONFLICT (dedupe_id) DO NOTHING` and registers
`DB::afterCommit(fn () => DeliverAdConversions::dispatch())`.

| Event key | Called from | Inside which transaction | `dedupe_id` |
|---|---|---|---|
| `lead` | Deal creation (`Deal::created` listener, any source: enquiry form, staff, AI agent) | The deal insert | `deal:{id}:lead` |
| `reservation` | Offer acceptance (invariant 12) **and** direct reservation create | The reservation insert | `reservation:{id}:created` |
| `move_in` | `ContractSigning::complete()` (invariant 20, sole implementation) | The signing transaction | `contract:{id}:move_in` |

Rules:
- Never emit from automation-originated writes twice. The dedupe id makes
  re-emission a no-op anyway, so no `AutomationContext` special case is needed.
- `move_in` value when the mapping's `value_rule = first_period_net`:
  Σ `net_amount` of the first-period charges with `ChargeType::isRevenue()` written
  in that signing transaction (deposit excluded). Computed with `BillingMath`,
  currency = `contracts.currency`. A €0 first period (free-time discount, D-DISC)
  sends value `0.00`, not null.
- Walk-ins with no prior touch still emit `move_in`. Delivery decides whether
  anything is sendable (Meta can match on hashed email/phone).

## Delivery

`DeliverAdConversions` job (queue `ads`) plus the `ads:deliver-conversions`
sweeper (every 5 minutes, authoritative, like `automations:resume-waiting`):

1. **Materialise.** For each event without deliveries, insert one row per active,
   non-archived account with an active mapping for that key
   (`ON CONFLICT DO NOTHING`).
2. **Claim** a batch per account: `UPDATE … SET status='claimed', claimed_at=now()
   WHERE status='pending' … RETURNING` (invariant 81 pattern).
3. **Gate**, in this order, per row. The first failing gate sets `skipped` + reason:

   | Gate | Skip reason |
   |---|---|
   | Account inactive or archived | `account_inactive` |
   | Mapping inactive | `mapping_inactive` |
   | `ConsentState::isGranted(contact, 'ads', advertising list)` false | `no_consent` |
   | No usable identifier for this platform (below) | `no_identifier` |
   | Google: chosen click older than 90 days at conversion time | `click_too_old` |
   | Meta: `occurred_at` older than 7 days at send time | `event_too_old` |

4. **Build** the payload in memory. Hash identifiers via `Hashing` (lowercased,
   trimmed email; E.164 phone, digits only, no `+`; SHA-256 hex). Write only the
   identifier **names** to `identifiers_used`.
5. **Send** in batches (Google ≤ 2,000 conversions per request with
   `partial_failure = true`; Meta ≤ 1,000 events). Map per-row results back.
   Retryable errors return the row to `pending` with `attempts + 1` and backoff
   (1, 5, 30, 120 min). After 5 attempts, or on a non-retryable error: `failed` +
   `error`.

### Identifier rules

| Platform | Required (at least one) | Added when available |
|---|---|---|
| Google Ads | `gclid` / `gbraid` / `wbraid` from the chosen touch | `hashed_email`, `hashed_phone_number` (enhanced conversions) |
| Meta | `fbc`, `fbp`, hashed `em`, or hashed `ph` | `external_id` = SHA-256 of `contact:{id}` |

**Touch choice:** the latest touch for the contact whose `platform` matches the
account's provider and whose `occurred_at` is before the event. Store it on
`marketing_touch_id`. This is the computed attribution of A3 applied at send time.
It is not stored on the deal.

### Payload sketches

Google `customers/{id}:uploadClickConversions`:
`gclid|gbraid|wbraid`, `conversionAction` (mapping `external_ref`),
`conversionDateTime` (formatted in the **account** timezone with offset),
`conversionValue` + `currencyCode` (move_in only), `orderId = dedupe_id`,
`userIdentifiers[]`, `consent: {adUserData: GRANTED, adPersonalization: GRANTED|DENIED}`
(from the latest touch consent snapshot; `ad_user_data` is necessarily granted
here or the gate skipped it).

Meta `POST /{pixel_id}/events`:
`event_name` (mapping `external_ref`), `event_time` (unix), `event_id = dedupe_id`,
`action_source = "system_generated"`, `user_data {em[], ph[], fbc, fbp, external_id[]}`,
`custom_data {value, currency}` (move_in only).

## Conversion targets

Add interface `ListsConversionTargets::conversionTargets(): list<{ref, name, kind}>`.
- Google: GAQL `SELECT conversion_action.resource_name, conversion_action.name,
  conversion_action.type, conversion_action.status FROM conversion_action
  WHERE conversion_action.type = 'UPLOAD_CLICKS' AND conversion_action.status = 'ENABLED'`.
- Meta: static list of standard events (`Lead`, `Schedule`, `SubmitApplication`,
  `Purchase`). Free-text custom names are allowed with validation
  (`^[A-Za-z0-9_]{1,40}$`).

## API

| Method | Path | Permission |
|---|---|---|
| `GET` | `ad-platform-accounts/{id}/conversion-targets` | `advertising.manage` |
| `GET` / `PUT` | `ad-platform-accounts/{id}/conversion-mappings` | view / manage. `PUT` upserts the three event keys at once |
| `POST` | `ad-platform-accounts/{id}/test-conversion` | manage. Google `validateOnly = true`; Meta `test_event_code` from the request. Writes nothing. |
| `GET` | `ad-conversion-deliveries` | view. Filters: account, status, skip_reason, event_key, from/to, contact. Paginated. |
| `POST` | `ad-conversion-deliveries/{id}/retry` | manage. Only from `failed` → `pending`, `attempts` reset. Tier-2 activity `ads.delivery.retried`. |
| `GET` | `ad-conversion-deliveries/summary` | view. Counts by status × skip_reason × account over a period (derived, never stored) |

Mapping changes log Tier-2 `ads.conversion_mapping.updated` (properties: account,
event key, old/new ref and value rule).

## System events

Tier-1 `ads.delivery.batch_failed` (account, count, error class) when a whole
request fails. `ads.delivery.partial_failure` with row count only. Never payloads.

## Acceptance criteria

- [ ] Signing a contract writes the outbox row **in the signing transaction**. A
      rolled-back signing leaves no event.
- [ ] Re-running the job and the sweeper concurrently sends each (account, event)
      once (claim test, same shape as `CampaignDispatchTest`).
- [ ] Withdrawn `ads` consent between event and delivery → `skipped / no_consent`.
- [ ] A Google delivery with only a hashed email and no click id → `no_identifier`.
      The same contact on Meta → `sent`.
- [ ] Value equals Σ first-period revenue net, excluding deposit. A free-time
      first period sends `0.00`.
- [ ] `identifiers_used` never contains a value. A test asserts no raw email,
      phone or click id appears anywhere in the `ad_conversion_deliveries` row.
- [ ] Retry works only from `failed`.
- [ ] HTTP fakes assert the exact Google/Meta request shapes, including
      `orderId`/`event_id` = `dedupe_id`.
