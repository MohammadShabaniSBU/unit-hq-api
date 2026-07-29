# Architecture — Pricing Model (final)

> Decision record. Closes the price-schema discussion of S02 planning.
> Supersedes the `Price` sections of `02-facility.md` and `03-pricing.md`, which must be
> rewritten to match as part of S02-00a.
> **Read before touching anything that stores or reads a monetary amount.**

---

## 1. The rule

**A price — any amount expressing what something costs — lives only in `prices` and is
referenced by `price_id`. Currency lives on the price row and nowhere else.**

**Ledger rows (`charges`, `payments`, `allocations`) record monetary events, not prices.**
They snapshot `amount` + `currency` at write time, are self-describing forever, and carry
**no** `price_id`. This is what Verifactu's self-contained invoice records require (S04) and
what makes a 2026 charge readable in 2030 without a join.

**Deposits are one-time agreed sums, not recurring prices.** They stay as snapshot columns
(`contracts.deposit_amount`, per invariant 18), in the same category as charges.

## 2. Schema

```sql
prices (
    id              BIGSERIAL PRIMARY KEY,
    priceable_type  VARCHAR(32) NULL,     -- unit_class_rate | insurance_rate
    priceable_id    BIGINT NULL,
    scope           VARCHAR(16) NOT NULL, -- catalogue | contract
    amount          NUMERIC(10,2) NOT NULL,
    currency        CHAR(3) NOT NULL,
    effective_from  DATE NULL,
    effective_to    DATE NULL,
    created_by      BIGINT NULL REFERENCES employees(id),
    created_at      TIMESTAMP
);

-- shape is enforced, not conventional:
ALTER TABLE prices ADD CONSTRAINT prices_scope_shape CHECK (
  (scope = 'catalogue' AND effective_from IS NOT NULL AND priceable_id IS NOT NULL)
  OR
  (scope = 'contract'  AND effective_from IS NULL AND effective_to IS NULL)
);

-- exactly one current catalogue price per owner:
CREATE UNIQUE INDEX prices_current_catalogue_idx
  ON prices (priceable_type, priceable_id)
  WHERE scope = 'catalogue' AND effective_to IS NULL;

-- Postgres only (btree_gist already installed by S01):
ALTER TABLE prices ADD CONSTRAINT prices_catalogue_no_overlap
  EXCLUDE USING gist (
    priceable_type WITH =,
    priceable_id   WITH =,
    daterange(effective_from, effective_to, '[)') WITH &&
  ) WHERE (scope = 'catalogue');
```

**Dropped:** `prices.billing_period` (legacy; cadence is a contract snapshot),
`unit_class_rates.price_id` (see §5).

## 3. Where the timing lives — one place per scope

| Scope | Timing lives on | Why |
|---|---|---|
| `catalogue` | The price row's `effective_from` / `effective_to` | `unit_class_rates` is created **once** per class × site and never versioned; the price rows are the timeline of that pairing |
| `contract` | The contract item version (S02-00) | The price is a value the contract agreed; the item version says when it applied |

Because each fact-about-time exists in exactly one place, no reconciliation rule is needed
and no divergence is possible. A contract's billing never reads a price's window; the
catalogue's timeline never reads an item version.

`effective_to` is exclusive (`[)`), matching `unit_occupancies`, `contract_items` and the
billing boundary convention.

## 4. Immutability — invariant 2, restated precisely

> **`prices.amount`, `currency`, `scope`, `priceable_type` and `priceable_id` are never
> updated.** The only permitted write to an existing price row is `effective_to`:
> NULL → a date, exactly once, in the same transaction that inserts the successor row.
> Nothing is ever deleted.

Replace the current wording of invariant 2 in `09-conventions-and-invariants.md` with the
above, and add:

> **2b. Contract item immutability** (from S02-00) — item versions are superseded, never
> updated; every version carries a required `price_id`.

The same NULL→date-once rule already governs `tax_rates.effective_to`; state that
explicitly there so the two versioned catalogues share one documented idiom.

## 5. `unit_class_rates` and `insurance_rates` — static junctions

The junction identifies the class × site (or insurance × site) pairing and is created once.
It carries **no** `price_id` and **no** effective dates.

- **Current price** of a pairing = its price row `WHERE scope='catalogue' AND effective_to
  IS NULL`. The partial unique index guarantees exactly one.
- **Price history** of a pairing = its price rows ordered by `effective_from`.
- The Rates matrix reads current catalogue prices via the junction; contract-scoped prices
  never appear in it because the matrix filters `scope = 'catalogue'`.

The previous documented flow ("insert a new junction row, close the old one") is
**superseded** — it described versioning the junction; the versioning lives on prices.

## 6. Operational flows

| Event | Writes |
|---|---|
| Catalogue rate change | Close current catalogue price, insert successor. Junction untouched. Activity `rate.changed` unchanged. |
| Contract signing | Item version's `price_id` → the pairing's **current catalogue price**. No copy. A later catalogue change closes that row's window but never its amount, so the reference stays true forever. |
| Contract rate change (S02-04) | Insert `scope='contract'` price owned by the same `unit_class_rate`; new item version references it. |
| Transfer, `destination_rate` (S02-03) | No new price — reference the destination pairing's current catalogue price. |
| Transfer, `retain_rate` | Reuse the origin item's `price_id`. |
| Insurance re-pricing | Identical, owner = `insurance_rate`. |

**Rule of thumb: a price row is inserted only when a new amount comes into existence.**
References are cheap and safe because rows are immutable.

Ownership on contract-scoped prices means "every amount this class has ever been priced at,
including negotiated one-offs" is a single query over `prices` by owner — with `scope`
separating the official timeline from the deals.

## 7. Amount columns to eliminate

As encountered (not a dedicated migration sweep):

| Column | Fate |
|---|---|
| `contract_items.amount` | Dropped in S02-00; `price_id` becomes required. Reads go through the price. |
| `offer_options` price reference | Already a `price_id`; verify no shadow amount column exists |
| Fixed-amount discounts | `price_id`; percentage discounts keep a `percentage` column (a percentage is not an amount) |
| `charges.*_amount`, `payments.amount`, `allocations.amount` | **Stay** — event snapshots by design |
| `contracts.deposit_amount`, `BillingSettings.default_deposit_amount` | **Stay** — one-time sums, snapshot semantics |

## 8. Migration order (S02-00a, before S02-00)

1. Add `priceable_*`, `scope`, CHECK, indexes to `prices`; drop `billing_period`.
2. Backfill owners: every existing price is reachable from exactly one junction row today —
   set `priceable_*` from it, `scope = 'catalogue'`. Any price row reachable from no
   junction is reported, not guessed.
3. Rewrite Rates matrix + rate-change flow to read/write prices by owner.
4. Drop `unit_class_rates.price_id` / `insurance_rates.price_id`.
5. Rewrite the `Price` sections of `02-facility.md` and `03-pricing.md`; restate invariant 2.

Seeders: create the catalogue price timeline per pairing (at least one pairing with a
closed historical price so history UIs have content).

## 9. Tests that pin the model

| Test | Asserts |
|---|---|
| `PriceModelTest::one_current_catalogue_price_per_owner` | Partial unique index semantics |
| `PriceModelTest::contract_scope_rejects_windows` | CHECK fires |
| `PriceModelTest::catalogue_scope_requires_owner_and_window` | CHECK fires |
| `PriceModelTest::amount_update_is_impossible` | Model guards + observer raise |
| `PriceModelTest::effective_to_settable_once_only` | Second write rejected |
| `PriceModelTest::catalogue_change_leaves_junction_untouched` | Static junction |
| `PriceModelTest::contract_reference_survives_catalogue_change` | Signing reference stays true |
| `Pgsql/PriceConstraintTest::catalogue_windows_never_overlap` | Exclusion constraint |
