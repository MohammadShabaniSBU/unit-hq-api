# S01-00b — Currency integrity

## Context

D1 says `prices.currency` is authoritative and a contract snapshots amount **and** currency.
That decision is written down in task 00. This task makes the schema and the API obey it.

The defect that motivates it is visible in the running app: the Rates matrix renders unit class
AL6 at Port Mose Storage as **€196.72**. Contract #7, unit AL6-06 at that same site, renders
**£196.72**. Same underlying price row, two symbols. The Rates resource evidently emits a
currency; the contract resource evidently does not, so the panel falls back to locale and
prints the wrong symbol against a real amount. An operator reading either screen has no way to
know which one is lying.

Price-as-authority covers everything with a price row behind it. It does not cover the money
that has none:

- **Deposit** — `contracts.deposit_amount` comes from `BillingSettings.default_deposit_amount`,
  a bare NUMERIC. The deposit charge has no `contract_item_id` and no price to inherit from.
- **Late fees and lien fees** — generated from rules by the S08 engine.
- **`adjustment`, `write_off`, `refund`** — added in task 00; none originates from a price.
- **Payments and allocations** — a GBP payment allocated against a EUR charge is nonsense, and
  Stripe hands you a currency on every event.

All of that resolves to one rule. `balance = Σ charges − Σ payments` is only meaningful within
a single currency, so: **one contract, one currency**, established at signing, asserted on
every row of the ledger it opens.

This task also executes the `invoices` → `billing_periods` rename decided in D5, because it
touches the same tables in the same migration batch.

## Scope

**In:**
- `sites.currency` (nullable prefill) + wire site→org prefill readers (first real readers;
  deferred from task 00 so the column is not unread)
- `revenueByCurrency()` aggregate + `ChargeTypeTest::revenue_is_grouped_by_currency` after
  `charges.currency` exists (deferred from task 00 — nothing to group by without the column)
- Currency snapshot columns on `contract_items`, `contracts`, `charges`, `payments`
- One-contract-one-currency assertion at signing and at every ledger write
- Site-vs-price currency validation on the rate junctions
- Audit and fix of every API resource emitting an amount without a currency
- Panel money-rendering rule enforced; symbol literals removed
- `invoices` → `billing_periods` rename (table, model, resource, routes, panel)

**Out:**
- Multi-currency reporting UI — S17
- FX conversion of any kind. There is none, ever. Amounts are never converted; they are
  grouped.
- `legal_entities` — S03
- Fiscal invoice model — S03

## Schema changes

Check each column before adding — some may exist.

```
-- migration: add_currency_to_sites (D1 prefill; first readers land in this task)
ALTER TABLE sites ADD COLUMN currency CHAR(3) NULL;            -- allowlisted EUR/GBP; form prefill only

-- migration: add_currency_to_contract_items
ALTER TABLE contract_items ADD COLUMN currency CHAR(3) NULL;   -- → NOT NULL after seed rework

-- migration: add_currency_to_contracts
ALTER TABLE contracts ADD COLUMN currency CHAR(3) NULL;        -- → NOT NULL after seed rework

-- migration: add_currency_to_ledger
ALTER TABLE charges  ADD COLUMN currency CHAR(3) NULL;         -- → NOT NULL after seed rework
ALTER TABLE payments ADD COLUMN currency CHAR(3) NULL;

-- migration: rename_invoices_to_billing_periods
ALTER TABLE invoices RENAME TO billing_periods;
-- plus the FK column on any child table: charges.invoice_id → charges.billing_period_id
-- (check which tables reference it before writing this)
```

Add nullable, populate in the same batch (there is no live data — the seeders are the only
writer), then tighten to `NOT NULL` in a follow-up migration in the same PR. Do not ship the
nullable state.

**Why carry currency on `charges` and `payments` rather than inherit from the contract:** it
makes an allocation's currency check a local column comparison instead of a two-hop join, and
it makes a Stripe webhook with a mismatched currency fail loudly at insert rather than
silently allocating. Two columns for a hard failure at the boundary is a good trade.

`allocations` needs no currency column — it references one charge and one payment, and the
assertion is that those two match.

## Implementation notes

### The assertion

`App\Support\Billing\CurrencyGuard` — static, no state, same tier as `BillingMath`:

```php
/** Every item resolves to the same currency, or throw. Returns the agreed currency. */
public static function assertItemsAgree(Collection $items): string;

/** A ledger row's currency matches its contract, or throw. */
public static function assertMatchesContract(Contract $contract, string $currency): void;

/** A payment and a charge share a currency before an allocation is written. */
public static function assertAllocatable(Charge $charge, Payment $payment): void;
```

Call sites:

- **Contract store and reservation convert.** After `ContractItem` rows are built and before
  charges are generated: `assertItemsAgree()`, write the result to `contracts.currency`. Inside
  the existing transaction, per invariant 20. A mixed-currency contract is a 422 with a
  translatable key, not a 500.
- **First-period charge generation** (`ContractBilling` / `GeneratesFirstPeriodCharges`): each
  charge inherits `contracts.currency`. The deposit charge too — that is the case with no price
  row behind it, and it is the one that would otherwise be silently unlabelled.
- **Every future charge writer** — recurring job (S04), dunning (S08), manual adjustments.
  Route them through the same helper now so S04 has nothing to remember.
- **Payment creation** — manual, Stripe webhook, SEPA settlement later.
  `assertMatchesContract()` on write.
- **Allocation creation** — `assertAllocatable()`.

`contracts.currency` is a snapshot of a fact resolved at signing, in the same family as
`tax_rate_snapshot`, `billing_anchor_date`, and `billed_through`. Name it that way in the
migration comment and in `09`, or it will get flagged against invariant 5 ("never store derived
state") by a future reader who is otherwise right to be suspicious.

### Prefill readers and `revenueByCurrency`

Wire price-form prefill: site `currency` when set, else org `BillingSettings.defaultCurrency`.
That is the first production reader of `sites.currency` — do not ship the column without it.

After `charges.currency` exists, implement `revenueByCurrency(iterable $charges)` grouping
revenue (`ChargeType::isRevenue()`) by ISO currency — never a scalar cross-currency total.
Task 00 recorded the rule and shipped `isRevenue()` only.

### Rate junction validation

`sites.currency` is not authoritative under D1 but earns its keep as a write-time guard:
attaching a EUR price to a `unit_class_rates` or `insurance_rates` row for a site whose prefill
currency is GBP is almost certainly a mistake, and it stays invisible until a contract is
signed against it.

**Decide hard-fail vs. warn, and record the choice.** Recommendation: hard-fail with an
explicit override flag on the request, because the failure mode of a wrong currency on a rate
is a wrongly-denominated contract, and there is no legitimate routine reason to mix.

**Check first, because it may be a data problem rather than a validation problem:** the Rates
matrix shows every class at an identical amount across all five sites — AL1 at €102.15 five
times, AL6 at €196.72 five times. If those five rate rows point at **one shared price row**,
currency is shared across sites by construction and the first UK site forces a fan-out into
per-site price rows. If they are five separate price rows that happen to hold equal amounts,
nothing needs changing. Report which before writing the validation.

### Resource audit — the actual work

The rule: **every API field carrying an amount carries its currency.** Mechanical, finite,
grep-able.

1. List every API resource field that emits a money value. Start from `NUMERIC(10,2)` columns
   and from `BillingMath` outputs; include computed fields (balance owed, overdue, unallocated
   credit) which have no column at all.
2. For each, note whether a sibling currency is emitted.
3. Fix the ones that aren't.

Known suspects from the panel screenshots: the contract resource (Line items, Billing summary —
balance owed, overdue, unallocated credit), the contact resource (Monthly rate card, Rented
unit card), the offer option resource (renders `€` correctly, verify it is from the record),
the unit map bulk payload (balance-owed status in the tooltip, task 04).

**Shape.** Pick one and use it everywhere. Recommendation — a sibling scalar, not a nested
object:

```json
{ "amount": "196.72", "currency": "EUR" }
```

Amounts stay strings per invariant 10. If a resource emits several amounts in one currency
(a contract's billing summary), one `currency` field for the group is acceptable and better
than repeating it five times — but the field must exist, and the grouping must be obvious from
the JSON structure rather than from a convention a reader has to know.

### Panel enforcement

- One formatter: `useMoney()` or `formatMoney(amount, currency, locale)` wrapping
  `Intl.NumberFormat(locale, { style: 'currency', currency })`. Every money render goes
  through it.
- **Zero currency symbol literals.** Grep the panel for `€`, `£`, `$` in `.vue` and `.ts` and
  in `en.json` / `es.json` / `fr.json`. A symbol baked into a translation string is the same
  defect wearing a costume.
- A money value arriving without a currency is a **visible** failure, not a silent fallback to
  locale. Render an em dash and log. Silent fallback is exactly how the current bug survived.
- Types: `Money = { amount: string; currency: string }` in `app/types/`, arrays as `Array<T>`.

### The rename

`invoices` → `billing_periods` per D5. Table, model (`Invoice` → `BillingPeriod`), resource,
routes (`/api/invoices` → `/api/billing-periods`), FK columns on children, panel pages, i18n
keys, morph map entry if present. Nothing else claims the name `invoices` afterwards — S03's
fiscal document takes it.

The panel Billing nav currently lists "invoices". Rename the route and the label; the operator-
facing word can stay "Invoices" in i18n for now **only if** you are comfortable renaming it
again in S03. Cleaner: label it "Billing periods" now and let S03 introduce "Invoices" meaning
the fiscal document.

## API surface

- Every money-bearing resource gains a currency field. Shape per above.
- `GET /api/invoices*` → `GET /api/billing-periods*`. No aliasing, no deprecation window —
  there is no external consumer.
- 422 with translatable keys for: mixed-currency contract items, ledger row currency mismatch,
  allocation across currencies, rate junction currency mismatch.

## Panel surface

- All money renders through the single formatter.
- Contract page, contact page, offer page, rates matrix, unit class matrix: verify each renders
  the currency from the record. The contract page is the known-bad one.
- Billing → Invoices route and labels renamed.
- New i18n keys for the four 422 messages, in `en.json`, `es.json`, `fr.json`.

## Invariants

Quoting `09-conventions-and-invariants.md` as amended by task 00:

> **29. Currency lives on the price row.** A contract snapshots the amount **and** the
> currency, not just the `price_id`.

> **31. No money value crosses an API boundary without its currency**, and no panel component
> contains a currency symbol literal.

New invariant to add in this task:

```markdown
35. **One contract, one currency.** `contracts.currency` is resolved from the contract's items
    at signing and is immutable thereafter. Every `charge`, `payment`, and `allocation`
    attached to that contract carries or matches it. `balance = Σ charges − Σ payments` is
    only ever computed within a single currency. Amounts are never converted between
    currencies — anywhere, for any reason.
```

Also relevant:

- Invariant 2 — price immutability. A site's currency change is new price rows, never an update.
- Invariant 3 — ledger append-only. A wrongly-denominated charge is corrected by a reversal row,
  not an edit. Which is precisely why the assertion belongs at write time.
- Invariant 5 — `contracts.currency` is a snapshot of a resolved fact, in the same family as
  `billing_anchor_date`. Not cached derived state. Say so in the migration comment.
- Invariant 10 — amounts stay `NUMERIC(10,2)` and serialize as strings.

## Acceptance criteria

- [ ] `sites.currency` exists (nullable, allowlisted); price forms prefill site → org default.
- [ ] `revenueByCurrency()` groups by currency; `ChargeTypeTest::revenue_is_grouped_by_currency`
      passes.
- [ ] `contract_items.currency`, `contracts.currency`, `charges.currency`, `payments.currency`
      exist, are `NOT NULL`, and are populated for every seeded row.
- [ ] Signing a contract whose items resolve to different currencies returns 422 with a
      translatable key and writes nothing.
- [ ] The deposit charge carries the contract currency despite having no `contract_item_id`.
- [ ] A payment whose currency differs from its contract is rejected at write.
- [ ] An allocation across two currencies is rejected at write.
- [ ] Attaching a price to a rate junction whose site prefill currency differs is rejected (or
      warned — whichever was decided, with the decision recorded in `10-open-decisions.md`).
- [ ] The shared-price-row question is answered in the PR description: do the five sites share
      one price row per class, or hold five?
- [ ] **Every API resource field carrying an amount emits a currency.** The audit list is in the
      PR description with a line per field and its resolution.
- [ ] `grep -rn '€\|£\|\$' app/` in the panel returns no hits in money-rendering code or in
      locale files.
- [ ] Contract #7 and the Rates matrix render the same symbol for the same price row. Verified
      by eye against a fresh seed, and named in the PR.
- [ ] Money arriving without a currency renders an em dash and logs, rather than guessing.
- [ ] `invoices` is renamed to `billing_periods` end to end; no code, route, or i18n key
      references `invoice` except where S03's fiscal document is being anticipated in a comment.
- [ ] `php artisan test` green; `bun run lint` and `bun run typecheck` green.

## Tests required

| Test | Asserts |
|---|---|
| `ChargeTypeTest::revenue_is_grouped_by_currency` | Two currencies in, two buckets out, no scalar total |
| `ContractCurrencyTest::currency_snapshotted_from_items_at_signing` | `contracts.currency` set, matches item prices |
| `ContractCurrencyTest::mixed_currency_items_rejected` | 422, no partial write |
| `ContractCurrencyTest::item_snapshots_currency_alongside_amount` | Not derived from the price at read time |
| `ContractCurrencyTest::deposit_charge_carries_contract_currency` | The no-price-row path |
| `ContractCurrencyTest::first_period_charges_carry_contract_currency` | Every generated charge |
| `ContractCurrencyTest::superseded_price_does_not_change_signed_contract` | Re-rate the class, contract unchanged |
| `LedgerCurrencyTest::payment_currency_must_match_contract` | 422 |
| `LedgerCurrencyTest::allocation_across_currencies_rejected` | 422 |
| `LedgerCurrencyTest::balance_computed_within_one_currency` | No cross-currency sum is reachable |
| `RateCurrencyTest::site_currency_mismatch_on_rate_junction` | Per the decided hard-fail/warn behaviour |
| `ResourceCurrencyTest::every_money_field_has_a_currency` | Iterate the audited resources; fails when a new unlabelled money field appears |
| `BillingPeriodRenameTest::legacy_invoice_routes_are_gone` | 404, and the new routes work |
