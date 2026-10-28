# S02-00 — Contract item effective dating

## Context

`contract_items` carries a flat `amount` with no validity window. That is fine for a contract
that never changes, and useless for one that does. Two of this sprint's three transitions are
blocked on it:

- **Transfer** needs "unit A until the 14th, unit B from the 14th" on one contract.
- **Rate change** needs "€196.72 until 1 April, €215.00 from 1 April" without mutating
  anything — invariant 2 forbids updating a price, and the same logic must hold for the price
  as recorded on a contract.

S05's recurring billing job is blocked on it too. The job's central question is "what was this
contract's price for the period starting D?", and today there is no way to ask.

This task adds versioning using the idiom the codebase already uses for `prices` and
`tax_rates`: **new row, close the old one with an end date, never `UPDATE`.**

## Scope

**In:**
- `effective_from` / `effective_to` on `contract_items`
- Supersession link and change reason
- Non-overlap constraint per contract × subject
- `effectiveOn()` query surface
- `ContractBilling` / `GeneratesFirstPeriodCharges` select items by date
- Seeder update

**Out:**
- Actually creating new versions (tasks 03 and 04 do that)
- Credit/adjustment charges for mid-period changes (tasks 03 and 04)
- Any change to `prices` or `unit_class_rates`

## Behaviour

A `ContractItem` becomes a **version**, not a row. The set of items "on" a contract is always
relative to a date.

- At signing, every item is created with `effective_from = contracts.move_in_date` and
  `effective_to = NULL`.
- A change closes the current version by setting its `effective_to` and inserts a successor
  with `effective_from` equal to that same date and `supersedes_id` pointing back.
- `effective_to` is **exclusive**, matching `unit_occupancies` and the billing boundary
  convention in `05-billing-ledger.md`. A version ending on the 1st and its successor
  starting on the 1st are contiguous, not overlapping.
- An existing version is **never re-pointed to a different price**. This is the
  contract-level expression of invariant 2 (see invariant 2b).
- Every version carries a **required** `price_id`; the `amount` column is **dropped**.
  Reads go through the referenced price, which also supplies currency. A version introducing
  a new amount references a newly inserted `scope='contract'` price; a version carrying an
  amount forward reuses the predecessor's `price_id`; the version created at signing
  references the pairing's current catalogue price directly. Contract-scoped prices carry
  **no effective window** — the item version is the only place contract timing lives.
  Full model: `../architecture-pricing.md` (read it before this task).

Existing charges keep pointing at the item version that produced them via
`charges.contract_item_id`. That is deliberate: a charge must remain traceable to the exact
priced line that generated it, even after the line is superseded.

## Schema changes

```sql
ALTER TABLE contract_items ADD COLUMN effective_from DATE NULL;
ALTER TABLE contract_items ADD COLUMN effective_to   DATE NULL;
ALTER TABLE contract_items ADD COLUMN supersedes_id  BIGINT NULL
    REFERENCES contract_items(id);
ALTER TABLE contract_items ADD COLUMN change_reason  VARCHAR(32) NULL;
    -- rate_change | transfer | correction | NULL for the original version

-- populate for seeded rows, then enforce
UPDATE contract_items ci SET effective_from = (
    SELECT c.move_in_date FROM contracts c WHERE c.id = ci.contract_id
) WHERE effective_from IS NULL;

ALTER TABLE contract_items ALTER COLUMN effective_from SET NOT NULL;

CREATE INDEX contract_items_effective_idx
    ON contract_items (contract_id, effective_from);

CREATE UNIQUE INDEX contract_items_open_version_idx
    ON contract_items (contract_id, itemable_type, itemable_id)
    WHERE effective_to IS NULL;
```

**Postgres non-overlap constraint** — `btree_gist` is already installed by S01:

```sql
ALTER TABLE contract_items
  ADD CONSTRAINT contract_items_no_version_overlap
  EXCLUDE USING gist (
    contract_id   WITH =,
    itemable_type WITH =,
    itemable_id   WITH =,
    daterange(effective_from, effective_to, '[)') WITH &&
  );
```

Guard behind a `pgsql` driver check as in S01-01. Note the constraint keys on the **subject**,
not the contract — a contract renting two units simultaneously is legal and must stay legal.
Only two overlapping versions *of the same subject* are forbidden.

Confirm the actual polymorphic column names before writing this; the docs say items are
polymorphic over unit and insurance but do not name the columns.

## Implementation notes

Add to the `ContractItem` model:

```php
public function scopeEffectiveOn(Builder $q, CarbonInterface $on): Builder
public function scopeEffectiveBetween(Builder $q, CarbonInterface $from, ?CarbonInterface $to): Builder
public function supersedes(): BelongsTo
public function supersededBy(): HasOne
```

`Contract::itemsOn(CarbonInterface $date)` returns the item set effective on that date. It
must return **exactly one version per subject** — assert this in a test rather than trusting
the constraint alone.

**Billing integration.** `ContractBilling` and `GeneratesFirstPeriodCharges` currently iterate
`$contract->items`. Replace with `$contract->itemsOn($periodStart)`. At signing,
`$periodStart` is `move_in_date`, so behaviour is unchanged — which is the point. Invariant 20
requires that `convert-preview` and real charge generation share this path; verify both call
sites change together.

**Do not add a "current amount" accessor on `Contract`.** It is derived state in disguise and
it will be wrong the moment a future-dated version exists. Callers pass a date.

## API surface

`GET /api/contracts/{id}` items array gains `effective_from`, `effective_to`,
`change_reason`, `supersedes_id`.

Add `?as_of=YYYY-MM-DD` to the contract show endpoint, defaulting to today, controlling which
item versions are returned. The existing Items tab count should reflect *current* versions,
not all versions ever.

## Panel surface

Minimal in this task — tasks 03 and 04 build the surfaces that create versions.

On the contract detail Line items card, show only versions effective today. Add a "History"
disclosure listing superseded versions with their windows and change reason, matching the
pattern used for unit occupancy history in S01-04.

## Invariants

> **2. Price immutability** — never `UPDATE` a price amount.

Extend this in `09-conventions-and-invariants.md`:

> **2b. Contract item immutability.** `contract_items.amount` is never updated. A price or
> subject change closes the current version via `effective_to` and inserts a successor linked
> by `supersedes_id`. Charges keep referencing the version that produced them.

> **18. Contract billing snapshots** — cadence, anchor, proration, deposit and tax are
> snapshotted at signing; later settings changes never rewrite existing contracts.

Item versioning must not touch any of those contract-level columns. A new item version
inherits the contract's existing snapshot; it does not re-read org settings.

> **20. Contract create writes first-period charges in the same DB transaction**, and preview
> must call the same path.

## Acceptance criteria

- [ ] Migration runs on SQLite and Postgres; seeded rows get `effective_from` from
      `move_in_date`.
- [ ] `contract_items.amount` is dropped; every seeded item version has a non-null
      `price_id` and reads its amount and currency through it.
- [ ] On Postgres, inserting two overlapping versions of the same subject raises a constraint
      violation.
- [ ] A contract renting two different units simultaneously is unaffected.
- [ ] `Contract::itemsOn($date)` returns exactly one version per subject for any date between
      move-in and end.
- [ ] First-period charge generation produces byte-identical results to before this task —
      compare against the existing billing test fixtures.
- [ ] `convert-preview` and contract store still share one code path.
- [ ] Contract detail shows current versions, with superseded versions behind a History
      disclosure.
- [ ] `09-conventions-and-invariants.md` gains invariant 2b.
- [ ] Seeder produces at least one contract with a superseded item version so the History
      disclosure has content.

## Tests required

| Test | Asserts |
|---|---|
| `ContractItemVersionTest::signing_creates_open_version` | `effective_from = move_in`, `effective_to` null |
| `ContractItemVersionTest::items_on_returns_one_version_per_subject` | Across several dates |
| `ContractItemVersionTest::adjacent_versions_are_contiguous` | Exclusive end, no gap, no overlap |
| `ContractItemVersionTest::multi_unit_contract_allowed` | Two open versions, different subjects |
| `ContractItemVersionTest::charges_retain_superseded_item_reference` | `contract_item_id` unchanged after supersession |
| `ContractItemVersionTest::future_version_not_returned_today` | Date filtering |
| `ContractItemVersionTest::every_version_has_price_id` | Required; `amount` column gone |
| `ContractItemVersionTest::signing_references_catalogue_price` | No copy row at signing |
| `ContractItemVersionTest::catalogue_change_does_not_alter_contract` | Reference stays true |
| `BillingRegressionTest::first_period_charges_unchanged` | Golden-file comparison against pre-task output |
| `Pgsql/ContractItemConstraintTest::overlapping_versions_rejected` | Postgres-only |
