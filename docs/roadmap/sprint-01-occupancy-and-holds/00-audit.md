# S01-00 audit scratch

Run before enabling ChargeType cast, currency allowlist, and jurisdiction validation.
Live `migrate:fresh --seed` DISTINCT queries were not executable in this environment
(`pdo_sqlite` / `pdo_pgsql` unavailable; only `pdo_mysql`). Results below are from
**static analysis of seeders, factories, and writers** — re-run the SQL on a seeded DB
before merge if a MySQL/Postgres host is available.

```sql
SELECT DISTINCT charge_type FROM charges;
SELECT DISTINCT currency FROM prices;
SELECT DISTINCT jurisdiction FROM tax_rates;
```

## Storage questions

| Question | Answer |
|---|---|
| `charge_type` storage | `string` / varchar (`create_charges_table`). No PHP enum, no native Postgres enum. → PHP `ChargeType` only; **no** DB migration. |
| `sites.currency` | Absent. **Not added in this task** (deferred to 00b with real readers). |
| `sites.country_code` | Absent. **Not added** — use existing `country_id` → `countries.code`. |
| `tax_rates.jurisdiction` | Nullable string column exists; validation is `nullable\|string\|max:10` only. |

## DISTINCT equivalents (seeded writers)

### `charge_type`

Writers:

- `BillingSeeder`: `'rent'`, `'insurance'` (plus copy of `$original->charge_type` on reversals)
- `GeneratesFirstPeriodCharges`: `'rent'`, `'insurance'`, `'deposit'` via `chargeTypeForItem` / deposit create
- `ChargeFactory`: `'rent'`

**Expected DISTINCT:** `rent`, `insurance`, `deposit` — all map to planned `ChargeType` cases.

### `prices.currency`

Writers:

- `DatabaseSeeder`: always `'EUR'` for unit-class and insurance prices
- Migration default: `'EUR'`

**Expected DISTINCT:** `EUR` only — inside allowlist `EUR`,`GBP`. No lowercase.

### `tax_rates.jurisdiction`

No seeder creates `tax_rates` rows. Table may be empty after seed.

**Expected DISTINCT:** empty / NULL only. Safe for regex validation.

## Cast blast radius

Casting `charge_type` on `Charge` throws on **read**. One bad row 500s any list endpoint
that hydrates charges (not only the bad row’s show). Correct pre-launch with no live data;
fix seeders before enabling the cast.

## `BillingSettings.defaultCurrency` call sites

| File | Use |
|---|---|
| `BillingSettings.php` | Property / serialize |
| `SettingController.php` | Validate `size:3` on update; return in show |
| `UnitClassPriceController.php` | **Stamps** `'currency' => $billing->defaultCurrency` on Price create — remove; require allowlisted request currency |
| `InsuranceRateController.php` | Same stamp — remove |

No other production readers. After this task, only settings UI + panel price-form prefill.

## `Carbon::today()` / date-boundary inventory (locate only; do not refactor)

| Site | Characterisation |
|---|---|
| `Contract::overdueAmount` (~287) | Overdue is computed per-charge against `Carbon::today()` in the **server** timezone. A Madrid tenant can show overdue a day early. Out of scope here; adopt `SiteClock` in S04/S08. |
| `ReservationController` convert (~284) | Defaults `start_date` via `now()->toDateString()` when omitted |
| `Unit` availability (~119, 158, 181) | `expires_at > now()` for active reservations |
| `Site` archive blockers | Same `expires_at > now()` pattern |
| `UnitClassPriceController` / `InsuranceRateController` | `Carbon::today()` for `effective_from` / closing old price |
| `TaxRateController` / `TaxRate::scopeActiveForCode` | Default `effective_from` / as-of date via `Carbon::today()` |
| `ContractController` / `GeneratesFirstPeriodCharges` / `ContractBilling` | `toDateString()` on civil dates already in hand — lower risk if inputs are dates |

Tasks 01–03 adopt `SiteClock` as they touch availability; billing overdue waits for S04/S08.

## charge_type grep blast radius (creates + reads)

**Creates / literals:** `GeneratesFirstPeriodCharges`, `BillingSeeder`, `ChargeFactory`.

**Reads:** No `where('charge_type'` / `whereIn('charge_type'` / `in:` charge-type validation found in `app/` at audit time. Re-grep after enum lands; prefer `ChargeType::…->value` if any appear.
