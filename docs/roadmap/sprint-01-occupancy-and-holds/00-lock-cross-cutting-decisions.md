# S01-00 — Lock cross-cutting decisions

## Context

Five ambiguities in the current docs will each cost a migration if resolved late. All five
touch code that Sprints 02–06 will write heavily. Resolve them now, in one pass, before any
schema work starts.

This task is mostly documentation plus two small migrations. It is deliberately first.

## Scope

**In:**
- Decide and document currency authority (site vs org)
- Decide and document VAT/tax-rate jurisdiction scoping
- Add missing `charge_type` enum values
- Add `fr` locale scaffolding to the panel
- Establish `Invoice` vs `Statement` naming

**Out:**
- Actually implementing per-site VAT resolution (that is S03)
- Translating the panel into French
- Any invoice model change

## Decisions to record

### D1 — Currency is resolved at site level

`sites.currency` is authoritative. `BillingSettings.default_currency` becomes the default
applied when a new site is created, and is never read at transaction time.

Add `App\Support\Billing\CurrencyResolver::forSite(Site $site): string`. Replace every
direct read of `BillingSettings.default_currency` outside site creation.

**Rationale:** clients operate in ES, FR and UK. A single org currency is wrong the moment
a second country is onboarded.

### D2 — Tax rates are jurisdiction-scoped

`tax_rates` gains a nullable `jurisdiction` usage rule: a rate with `jurisdiction = NULL`
applies anywhere; a rate with a value applies only to sites whose country matches. The
resolution order in `03-pricing.md` becomes:

1. Explicit `tax_rate_id` override on the request
2. Product `tax_rate_code` → active version **whose jurisdiction matches the site, else the
   NULL-jurisdiction version**
3. Org default tax rate, same jurisdiction filter
4. No tax (0%)

`sites` gains `country_code` (ISO 3166-1 alpha-2) if not already present — required for this
and for Verifactu in S06.

**Do not implement the resolution change here.** Record it, add `sites.country_code`, and
add the jurisdiction matching in S03 when the invoice model is reworked.

### D3 — Charge types to add now

Append to the `charge_type` enum: `adjustment`, `write_off`, `refund`.

- `adjustment` — manual correction, positive or negative, always paired with a reason
- `write_off` — operator forgives a debt; excluded from revenue like `deposit`
- `refund` — money returned to the tenant

Adding these now avoids a backfill when S02 (vacate) and S07 (dunning) need them.

### D4 — Panel locale `fr`

Create `locales/fr.json` as a copy of `en.json`, register `fr` in the i18n config, add it to
the language switcher. Translations may lag; the key set must not.

### D5 — `Invoice` vs `Statement`

- **Invoice** — a fiscal document. Numbered, immutable once issued, belongs to a series.
  From S03 onward this is the source of truth for what was billed, not a display grouping.
- **Statement** — a computed view of what a contact owes right now across contracts. Never
  a stored row. Never numbered.

Rename any existing panel usage of "statement" that actually means invoice, and vice versa.

## Schema changes

```
-- migration: add_country_code_to_sites
ALTER TABLE sites ADD COLUMN country_code CHAR(2) NULL;
-- backfill from existing address data where possible, else leave NULL and
-- make it required in the site form (task 04 of this sprint is unaffected)

-- migration: extend_charge_type_enum
-- Postgres: ALTER TYPE ... ADD VALUE for each of adjustment, write_off, refund
-- (or, if charge_type is a varchar + app-level enum, update the PHP enum only)
```

Check how `charge_type` is currently stored before writing this migration. If it is a PHP
enum backed by a string column, no migration is needed — update `ChargeType` only.

## Implementation notes

- `App\Support\Billing\CurrencyResolver` — new, thin, no state.
- Update `ChargeType` enum and any `isRevenue()`-style helper so `write_off` and `deposit`
  are both excluded from revenue.
- Search the API repo for `default_currency` and audit every call site.

## API surface

None new. `GET /api/sites` and the site resource gain `country_code`.

## Panel surface

- Site create/edit form: country selector, required.
- `locales/fr.json` registered; language switcher shows three options.

## Invariants

- Invariant 10 — money remains `NUMERIC(10,2)`; adding charge types must not introduce any
  float path.
- Invariant 19 — `deposit` is not revenue. Extend the same treatment to `write_off`.
- Panel convention — all new strings via i18n, including the country selector labels.

## Acceptance criteria

- [ ] `docs/10-open-decisions.md` updated: D1–D5 moved from "Undecided" to "Decided", with
      the rationale above.
- [ ] `docs/09-conventions-and-invariants.md` gains: "Currency is resolved from
      `sites.currency`; org billing settings supply defaults at site creation only."
- [ ] `docs/03-pricing.md` resolution order updated to include the jurisdiction filter,
      marked as *implemented in S03*.
- [ ] `sites.country_code` exists and is required in the panel form.
- [ ] `ChargeType` includes `adjustment`, `write_off`, `refund`.
- [ ] Revenue helper excludes `deposit` and `write_off`.
- [ ] `locales/fr.json` exists with the full key set; `bun run typecheck` passes.
- [ ] No production code reads `BillingSettings.default_currency` outside site creation.

## Tests required

| Test | Asserts |
|---|---|
| `CurrencyResolverTest::resolves_from_site` | Site currency wins over org default |
| `CurrencyResolverTest::falls_back_to_org_default_when_site_currency_null` | Fallback path |
| `ChargeTypeTest::write_off_and_deposit_excluded_from_revenue` | Revenue helper correctness |
| `SiteTest::country_code_required_on_create` | Validation |
