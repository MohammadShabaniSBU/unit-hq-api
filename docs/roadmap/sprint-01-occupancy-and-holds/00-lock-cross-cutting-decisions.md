# S01-00 — Lock cross-cutting decisions

## Context

Eight ambiguities in the current docs will each cost a migration if resolved late, and two of
them are already producing wrong output in the running app. All eight touch code that S02–S07
will write heavily. Resolve them now, in one pass, before any schema work starts.

This task is **documentation plus two small schema changes**. It deliberately contains no
money-path code — that is task `00b`, split out because it grew past a half day. Keep them
separate: this task is safe to do in one sitting and produces the written decisions that every
later session in the sprint reads cold.

Two of these decisions contradict what is currently written down elsewhere. Recording them is
not enough; the superseded text has to be struck, or a future Cursor session will read the old
doc and produce a hybrid. Task `05` does the striking for payments; this task does it for
currency.

## Scope

**In:**
- Decide and record D1–D8 in `10-open-decisions.md` and `09-conventions-and-invariants.md`
- Require `sites.country_id` (FK already exists; ISO via `countries.code` — no denormalised
  `country_code` column)
- `ChargeType` PHP enum + cast on `Charge` + `isRevenue()` (no `revenueByCurrency` — needs
  `charges.currency` in `00b`)
- `fr` locale scaffolding in the panel
- `App\Support\Time\SiteClock` — the date-boundary helper
- Currency allowlist on price create (`EUR`, `GBP`); stop stamping org default at write time
- Strike superseded currency-authority language from the roadmap and pricing docs
- Record the legal-entity question as blocking S03, with a named owner and a date

**Out:**
- Currency snapshot columns, resource audit, the `€`/`£` fix — task `00b`
- `sites.currency` column and site→org prefill readers — task `00b` (first real readers)
- `revenueByCurrency()` aggregate — task `00b` after `charges.currency`
- Per-site VAT / jurisdiction resolution — S03
- Renaming the `invoices` table — task `00b` (schema) though the decision is recorded here
- Translating the panel into French
- Any `legal_entities` schema — S03
- Any invoice model change — S03

## Decisions to record

### D1 — Currency lives on the price row

**`prices.currency` is the sole authority.** Any entity that needs a currency gets it from
the price row it references. `sites.currency` (added in `00b`) and
`BillingSettings.default_currency` are **form prefill defaults only** — they are read when an
operator opens a price form, and never at transaction time, never in a resource, never in a
rollup.

This works because prices are already immutable (invariant 2). A site that changes currency is
expressed as new price rows and closed `effective_to` on the old ones, which is the same
mechanism as any re-rate. There is nothing to migrate and no ambiguity about which currency a
historical rate was denominated in.

**A contract snapshots the amount *and* the currency**, not just the `price_id`. The
contract's ledger is then self-describing, and reading it never requires walking back to a
price row that may since have been superseded.

**Supersedes** roadmap README §4 item 1 ("site-level must win; org-level becomes the default
for new sites") and any reading of `03-pricing.md` that implies the org currency is
authoritative. Strike both in this task.

The schema work that follows from D1 is in `00b`. What lands here is the written rule, the
deletion of the contradicting text, and allowlisted currency on price create (prefill from
org default only until `00b` adds `sites.currency`).

### D2 — Tax rate jurisdiction vocabulary

`tax_rates.jurisdiction` already exists as "optional short code" with no defined vocabulary.
Lock the vocabulary now or S03 inherits unparseable data:

- `NULL` — applies anywhere. The fallback.
- **ISO 3166-1 alpha-2**, optionally with an **ISO 3166-2 subdivision**: `ES`, `FR`, `GB`,
  `ES-CN`, `ES-PV`.

The subdivision form is not speculative. The Canary Islands run IGIC at 7% instead of 21%
IVA, and Basque TicketBAI is already named in the fiscal architecture doc as a future regime.
A country-only vocabulary would need a second migration the first time either matters.

Validate the format on write in this task (regex + a `Jurisdiction` value object or an enum
of accepted codes — a plain regex is sufficient for now). **Do not implement the resolution
change here**; the resolution order in `03-pricing.md` becomes:

1. Explicit `tax_rate_id` override on the request
2. Product `tax_rate_code` → active version whose `jurisdiction` matches the site's
   subdivision, else its country (via `country_id` → `countries.code`), else the
   `NULL`-jurisdiction version
3. Org default tax rate, same jurisdiction filter
4. No tax (0%)

Record that order in `03-pricing.md` marked *implemented in S03*.

Site country for the above is **`country_id`** (FK → `countries`, which already holds ISO
3166-1 alpha-2 `code`). Do **not** add a denormalised `sites.country_code` — that drifts from
`countries.code`. Require `country_id` in the panel/API from now on; the DB column stays
nullable until S03 makes it `NOT NULL`.

### D3 — Charge types, signs, and revenue treatment

Append to `charge_type`: `adjustment`, `write_off`, `refund`. Introduce a PHP `ChargeType`
enum, cast it on `Charge`, and ship `isRevenue()` — false for `deposit`, `write_off`,
`refund`. Never `tryFrom`; unresolved strings throw.

The enum values are the easy part. The part that would require a backfill if got wrong is the
semantics, so write this table into `05-billing-ledger.md`:

| Type | Sign | Revenue | Notes |
|---|---|---|---|
| `adjustment` | ± | ± | Manual correction. Reason string required. Counts as revenue when positive and the underlying charge did. |
| `write_off` | negative | **excluded** | Operator forgives a debt. Carries a net/tax split mirroring the charge it forgives. VAT recovery treatment deferred to S03. |
| `refund` | positive | **excluded** | Money returned to the tenant. A **charge**, not a negative payment. |

`refund` as a charge is correct given `balance = Σ charges − Σ payments` — it debits the
tenant's credit — but it reads backwards, and left unstated it will be modelled as a negative
payment. Say it explicitly in the doc.

A refund does **not** reverse revenue; revenue reversal is a credit note (S03). Excluding
`refund` from `isRevenue()` is correct today (counting it would *increase* revenue when money
went out) but leaves the original rent in revenue after a refund — wrong-looking later unless
documented.

`write_off` cannot use `reversal_of_charge_id`, because partial write-offs exist. It is a new
charge with its own amount, optionally referencing the charge it relates to for reporting.

**Revenue is grouped by currency, never summed across it.** Record that rule in
`05-billing-ledger.md` and invariant 30 now. **Do not implement `revenueByCurrency()` here** —
`charges` has no `currency` column until `00b`, so there is nothing to group by. The aggregate
and `ChargeTypeTest::revenue_is_grouped_by_currency` land in `00b`.

Note for S08, do not act on now: `lien_fee` and the panel's "Liens & auctions" page are
US-shaped. The roadmap already records that there is no US-style lien statute in the EU.

### D4 — Panel locale `fr`

Create `locales/fr.json` as a copy of `en.json`, register `fr` in the i18n config, add it to
the language switcher. Translations may lag; the key set must not.

Tied to D1 by one rule, which is the rule that actually fixes the `€`/`£` bug:

> **Locale never determines currency.** All money renders through
> `Intl.NumberFormat(locale, { style: 'currency', currency })` where `currency` comes from
> the record being rendered. No currency symbol literal appears anywhere in the panel.

Enforcement is in `00b`. The rule is recorded here.

### D5 — `Invoice` vs `Statement`, and freeing the name

- **Invoice** — a fiscal document. Numbered, immutable once issued, belongs to a series.
  From S03 onward this is the source of truth for what was billed.
- **Statement** — a computed view of what a contact owes right now across contracts. Never a
  stored row. Never numbered.

The existing `invoices` table is neither: `05-billing-ledger.md` describes it as "display
grouping, not source of truth". **Rename it** — `billing_periods` — and leave the name
`invoices` free for the S03 fiscal document. There is no live data, so this is a rename
migration and a resource rename, roughly an hour. Doing it in S03 means disentangling a
shipped table under compliance pressure.

The schema rename executes in `00b` alongside the other billing-table work, so there is one
migration batch touching billing rather than two. Record the definitions and rename decision
in `05-billing-ledger.md` in this task.

### D6 / D7 — superseded: payments and fiscal identity scope to `legal_entities`

Roadmap README §4 D6 and D7 scope payment credentials and `fiscal_regime` to **sites**.
`architecture-payments-and-fiscal.md` scopes both to **`legal_entities`**. These cannot both
stand, and the architecture doc is right: a Verifactu hash chain is per issuer NIF, VAT
registration is per tax ID, and a SEPA creditor identifier belongs to a legal person. A site
is a building.

**Decision: `legal_entities` is the scope for payment credentials, invoice series, VAT
registration, and fiscal regime. Sites belong to one legal entity.**

Recording it here; task `05` does the doc surgery. Nothing is implemented in this sprint.

Carry forward the invariant already drafted in the architecture doc — `legal_entities` is a
fiscal concept, **not** a tenancy boundary, and must never appear in a global scope, a
middleware filter, a queue payload, or a default query constraint.

### D8 — Date boundaries resolve through the site timezone

`02-facility.md` already says `sites.timezone` is per-site and authoritative for date-boundary
interpretation. Nothing enforces it, and this sprint introduces four DATE columns plus an
availability query keyed on "today". Within a day of starting task 01 there will be a
`Carbon::today()` in availability code, and the server timezone becomes the real authority.

**Decision:**

- Date computation for a unit, contract, reservation, or hold resolves through the timezone of
  the **site** that owns it.
- The panel sends **explicit dates**; the API does not infer "today" from a request when a
  date could have been supplied.
- Where "today" genuinely has no site (a cross-site list under "All Sites"), it is computed
  **per site**, not once from a chosen site or from the server.
- `timestamp → date` conversion always names its timezone. A bare `->toDateString()` on a
  timestamp is a defect.

Helper, alongside `App\Support\Billing\` and `App\Support\Occupancy\`:

```php
namespace App\Support\Time;

final class SiteClock
{
    /** Current civil date at the site. */
    public static function today(Site $site): CarbonImmutable;

    /** Civil date at the site for an absolute instant — replaces bare ->toDateString(). */
    public static function dateAt(Site $site, CarbonInterface $instant): CarbonImmutable;

    /** Start of the civil day at the site, as an instant. */
    public static function startOfDay(Site $site, CarbonInterface $date): CarbonImmutable;
}
```

Static helpers, no state, no `app/Services/`. Same tier as `BillingMath`.

## Schema changes

```
-- No country_code column. sites.country_id already exists (FK → countries).
-- Require country_id in controller/panel validation from now on.
-- DB column stays nullable; becomes NOT NULL in S03 when every site must be placeable.

-- charge_type: adjustment, write_off, refund
-- CHECK STORAGE FIRST. If charge_type is a varchar backed by a PHP enum, update ChargeType
-- only and write no migration. If it is a native Postgres enum, ALTER TYPE ... ADD VALUE
-- cannot run inside a transaction — the migration needs $withinTransaction = false and must
-- be its own file, and it will not run on SQLite at all.

-- Do NOT add sites.currency here. That column lands in 00b with site→org prefill readers.
```

Nothing else in this task. The currency snapshot columns, the `invoices` rename, and
`sites.currency` are in `00b`. Jurisdiction format validation (not storage change) is in this
task.

## Implementation notes

**Audit before writing.** Report first, edit second:

- How is `charge_type` stored? Native enum, varchar + PHP enum, or something else.
- `SELECT DISTINCT charge_type FROM charges` on a seeded DB — every value must map to a case.
- `SELECT DISTINCT currency FROM prices` — must be uppercase allowlisted (`EUR`/`GBP`) before
  enforcing the allowlist (prices are immutable).
- `SELECT DISTINCT jurisdiction FROM tax_rates` — must pass the ISO regex before write
  validation (a bad seeded value blocks the next PATCH and looks like a validation bug).
- Does `sites.currency` exist? If absent, **do not add it here** — defer to `00b`.
- Every call site reading `BillingSettings.default_currency`.
- Every `Carbon::today()`, `now()->toDateString()`, `->toDateString()` on a timestamp, and
  `::date` cast in date-boundary code. Characterise `Contract::overdueAmount` (server-TZ
  overdue) as out of scope until S04/S08.

Write the audit to a scratch file and read it before proceeding. If `charge_type` is a native
Postgres enum the migration shape changes.

**Cast blast radius.** Casting `charge_type` throws on **read**. One bad row 500s any list
endpoint that hydrates it — correct pre-launch with no live data; note it in the audit.

**Revenue helper.** Ship `isRevenue()` only so `deposit`, `write_off`, and `refund` are
excluded. Grep charge creates **and** reads (`where('charge_type'`, `whereIn`, `in:`
validation) — query-builder comparisons bypass the cast.

**`SiteClock`.** Implement and unit-test it here, but do not go refactor existing call sites in
this task; tasks 01–03 adopt it as they touch that code. Record the rule so those tasks can
cite it.

### Text to append to `09-conventions-and-invariants.md`

```markdown
29. **Currency lives on the price row.** `prices.currency` is authoritative. `sites.currency`
    and `BillingSettings.default_currency` are form prefill defaults — never read at
    transaction time, in a resource, or in a rollup. A contract snapshots the amount **and**
    the currency, not just the `price_id`.

30. **Revenue is grouped by currency, never summed across it.** No aggregate returns a scalar
    money total spanning more than one currency.

31. **No money value crosses an API boundary without its currency**, and no panel component
    contains a currency symbol literal. Rendering is
    `Intl.NumberFormat(locale, { style: 'currency', currency })` with `currency` from the
    record. Locale never determines currency.

32. **Date boundaries resolve through the owning site's timezone** (`App\Support\Time\SiteClock`).
    Bare `Carbon::today()` and `->toDateString()` on a timestamp are defects in any code that
    produces or compares a DATE. Cross-site "today" is computed per site.

33. **`tax_rates.jurisdiction` is `NULL` (applies anywhere) or ISO 3166-1 alpha-2 with an
    optional ISO 3166-2 subdivision** (`ES`, `ES-CN`, `FR`). Validated on write.

34. **`legal_entities` is a fiscal domain concept, not a tenancy boundary.** It identifies the
    issuer of an invoice and the holder of payment credentials. It must never appear in a
    global scope, a middleware-applied filter, a queue payload context, or a default query
    constraint. Filtering an invoice *series* by entity is correct; filtering *contacts* by
    entity is a defect.
```

Invariant 34 is the one that will erode quietly. Re-read it at every retro.

## API surface

None new. Site create/update requires `country_id`. Price create requires allowlisted
`currency` from the request.

## Panel surface

- Site create/edit form: country selector (`country_id`), required. Labels via i18n.
- Price forms: currency select from allowlist; prefill from org billing default.
- `locales/fr.json` registered; language switcher shows three options.

## Invariants

- Invariant 2 — price immutability is what makes D1 work. Changing a site's currency is a
  re-rate, never an update.
- Invariant 10 — money stays `NUMERIC(10,2)`; adding charge types must not introduce a float
  path.
- Invariant 19 — `deposit` is not revenue. Extend the same treatment to `write_off` and
  `refund`.
- Panel convention — all new strings via i18n, including country and locale labels.

## Acceptance criteria

- [ ] `10-open-decisions.md`: D1–D8 recorded under "Decided (do not reopen)" with the rationale
      above. The roadmap README §4 items 1 and D6/D7 are marked superseded, in place, with a
      pointer to the replacement.
- [ ] `09-conventions-and-invariants.md` gains invariants 29–34 as written above.
- [ ] `03-pricing.md`: currency-authority sentence rewritten to D1; jurisdiction-aware
      resolution order recorded and marked *implemented in S03*.
- [ ] `05-billing-ledger.md`: the charge-type table from D3 added, including the `refund`-is-a-
      charge note, the refund-does-not-reverse-revenue note, the revenue-by-currency rule, and
      D5 Invoice/Statement + `billing_periods` rename decision.
- [ ] `02-facility.md`: required `country_id` documented (DB nullable → `NOT NULL` in S03);
      timezone paragraph gains a pointer to `SiteClock`.
- [ ] `docs/AGENTS.md` non-negotiables mention price-row currency and `country_id` (not a
      denormalised code).
- [ ] `country_id` is required in the panel form and site API validation.
- [ ] `ChargeType` includes `adjustment`, `write_off`, `refund`, cast on `Charge`, with the
      storage question resolved in the audit (varchar → PHP enum only, no DB migration).
- [ ] `isRevenue()` excludes `deposit`, `write_off`, `refund`. (`revenueByCurrency` is `00b`.)
- [ ] `tax_rates.jurisdiction` format validation exists and rejects `esp`, `ES_CN`, `Spain`.
- [ ] `App\Support\Time\SiteClock` exists with unit tests.
- [ ] `locales/fr.json` exists with the full `en.json` key set; `bun run typecheck` passes.
- [ ] No production code reads `BillingSettings.default_currency` outside price-form prefill.
- [ ] Price create validates currency against an uppercase allowlist (`EUR`, `GBP`).
- [ ] The legal-entity question is recorded in `10-open-decisions.md` under "Undecided" with a
      named owner and a target date, and flagged as blocking S03.
- [ ] The audit scratch file is attached to the PR.

## Tests required

| Test | Asserts |
|---|---|
| `ChargeTypeTest::write_off_refund_and_deposit_excluded_from_revenue` | `isRevenue()` correctness |
| `SiteTest::country_id_required_on_create` | Validation |
| `TaxRateTest::jurisdiction_accepts_null_country_and_subdivision` | `NULL`, `ES`, `ES-CN` |
| `TaxRateTest::jurisdiction_rejects_malformed_codes` | `esp`, `ES_CN`, `Spain`, `es-cn` |
| `SiteClockTest::today_uses_site_timezone` | Two sites, different zones, different civil dates at the same instant |
| `SiteClockTest::date_at_converts_instant_in_site_zone` | `23:30 Europe/Madrid` and `23:30 Europe/London` resolve to the dates a local operator would name |
| `SiteClockTest::does_not_read_app_timezone` | Changing `config('app.timezone')` does not change the result |
