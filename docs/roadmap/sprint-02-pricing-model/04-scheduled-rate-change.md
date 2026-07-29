# S02-04 — Scheduled rate changes

## Context

Existing-customer rate increases are the largest revenue lever in self-storage. Operators
raise tenants' rates on a schedule — typically annually, or at a fixed tenure milestone —
having given contractual notice. An operator whose system cannot do this has no way to grow
revenue from the book they already have, and will not switch to yours.

The mechanism is entirely provided by S02-00: a rate change is a **future-dated contract item
version**. The change is written into the data the moment it is scheduled, and simply becomes
effective on its date. Nothing has to run at midnight.

## Scope

**In:**
- Schedule a rate change on one contract item, effective on a future date
- Notice record and notice-period validation
- Amend or cancel a scheduled change before it takes effect
- Contract notices table (shared with S08)
- Panel scheduling UI and pending-change display

**Out:**
- **Bulk rate changes across many contracts** — deferred, recorded in `10-open-decisions.md`
- Automatic scheduling by rule ("+4% at 12 months tenure") — that is an automation playbook,
  S10
- Notice *delivery* — this task records that notice is required and when it was sent; actually
  sending it is S11–S14. Until then the operator marks it sent manually.
- Rate *decreases* are permitted by the same mechanism and need no special handling

## Behaviour

### Scheduling

Given a contract item, a new amount, and an effective date:

1. `ContractTransition::assert` — permitted from `active` and `notice_given`
2. Validate the effective date is in the future and respects the notice period (below)
3. Close the current item version: `effective_to = effectiveDate`
4. Insert the successor: `effective_from = effectiveDate`, new `amount`,
   `change_reason = 'rate_change'`, `supersedes_id` → current version
5. Insert a `contract_notices` row of type `rate_change`
6. `RecordsActivity::core('contract.rate_scheduled', …)`, money as strings

Tax is re-resolved and snapshotted onto the new version, since the catalogue may have
versioned since signing.

### Pricing under the final model

Full model: `../architecture-pricing.md`. As applied here:

A rate change **inserts a `prices` row** with `scope = 'contract'`, owned by the same
`unit_class_rate` as the item's current price (so the class's full price history — official
and negotiated — remains one query), and the new item version references it. Contract-scoped
prices carry **no effective window**; the item version's window is the only statement of when
the amount applies, enforced by the `prices_scope_shape` CHECK.

No `unit_class_rates` junction row is written and no catalogue price is touched. The Rates
matrix filters `scope = 'catalogue'` and is unchanged by any number of contract rate changes.

If a change re-applies an amount that already exists as a referenced price (rare here, normal
in transfers), reuse that `price_id` — a price row is inserted only when a new amount comes
into existence.

### Notice period

`contracts.rate_change_notice_days`, snapshotted at signing from site settings. The effective
date must be at least that many days after the notice date, or the request is rejected with a
422 naming the earliest permissible date.

The operator may override with an explicit `acknowledge_short_notice` flag plus a reason —
tenants sometimes agree to an immediate change. The override is recorded in the notice row and
the activity properties. Do not silently allow it.

### Amending and cancelling

While `effective_from` is still in the future, the scheduled version may be:

- **Amended** — the pending version's `amount` or date is replaced by closing it and writing
  another successor. Do not `UPDATE` the pending row; the audit trail of what was scheduled
  and then changed has value in a dispute.
- **Cancelled** — the pending version is closed with `effective_to = effective_from`
  (a zero-length window, which the `[)` range treats as empty and the exclusion constraint
  accepts), and the previously-current version is reopened by clearing its `effective_to`.

Reopening a superseded version is the one place this sprint updates an `effective_to` back to
`NULL`. It is legitimate — the window never elapsed — but it must be guarded: **cancellation
is rejected once `effective_from <= today`.** After that it is a new rate change, not a
cancellation.

### Once effective

Nothing happens. The recurring billing job in S05 reads `itemsOn($periodStart)` and picks up
the new amount automatically. There is no job to write here, and no state to flip. That is
the entire benefit of the effective-dating model.

## Schema changes

```sql
-- prices.scope, ownership and constraints ship in S02-00a (architecture-pricing.md §8)

ALTER TABLE contracts ADD COLUMN rate_change_notice_days SMALLINT NULL;

CREATE TABLE contract_notices (
    id                BIGSERIAL PRIMARY KEY,
    contract_id       BIGINT NOT NULL REFERENCES contracts(id),
    notice_type       VARCHAR(32) NOT NULL,
        -- rate_change | move_out_confirmation | payment_reminder
        -- | overdue | retention  (last three used from S08)
    effective_date    DATE NULL,
    required_by       DATE NULL,          -- earliest permissible effective date
    sent_at           TIMESTAMP NULL,
    sent_channel      VARCHAR(24) NULL,   -- email | sms | post | in_person
    sent_to           VARCHAR(255) NULL,
    document_ref      VARCHAR(255) NULL,  -- S15 signed/stored document
    short_notice_reason TEXT NULL,
    contract_item_id  BIGINT NULL REFERENCES contract_items(id),
    created_by        BIGINT NULL REFERENCES employees(id),
    created_at        TIMESTAMP,
    updated_at        TIMESTAMP
);

CREATE INDEX contract_notices_contract_idx ON contract_notices (contract_id, notice_type);
```

**`contract_notices` is built here but designed for S08.** The delinquency ladder needs a
defensible record of exactly which notice went to which address on which date — the same
shape. Building it once, now, avoids building it twice. Do not narrow the schema to rate
changes only.

Notices are **append-only**. A notice that was sent in error is superseded by another notice,
never edited or deleted.

## API surface

```
POST   /api/contracts/{id}/rate-changes
       { contract_item_id, new_amount, effective_date,
         acknowledge_short_notice?, short_notice_reason? }

PATCH  /api/contracts/{id}/rate-changes/{itemId}     -- amend pending
DELETE /api/contracts/{id}/rate-changes/{itemId}     -- cancel pending
POST   /api/contracts/{id}/notices/{noticeId}/sent   -- mark notice delivered
GET    /api/contracts/{id}/rate-changes              -- pending + historical
```

`POST` returns the resulting item versions and the notice row, including `required_by` so the
panel can explain a rejection.

## Panel surface

**Contract detail — Line items card.** A pending change shows inline on the affected line:
current amount, an arrow, the scheduled amount and its date, plus Amend and Cancel actions.
This is the highest-value display in the task; an operator must never be surprised by a rate
change they forgot they scheduled.

**Schedule drawer:**

- Line item selector (units and insurance both eligible)
- Current amount, read-only
- New amount, with the percentage delta shown live — operators think in percentages
- Effective date, with dates before `required_by` disabled and a tooltip explaining the notice
  period
- Short-notice override checkbox, revealing a required reason field
- Notice section: channel and recipient, defaulting to the contact's primary email channel

**Notices tab** on contract detail listing every notice with type, dates and sent state. This
tab becomes the delinquency audit trail in S08 — build it generic.

i18n under `contracts.rate_change.*` and `contracts.notices.*`. Spanish: rate change →
*Actualización de precio*, notice → *Notificación*, notice period → *Plazo de preaviso*.

## Invariants

- Invariant 2 (as restated in `architecture-pricing.md` §4) — a rate change inserts a
  `scope='contract'` price and updates nothing. No junction row; catalogue untouched.
- Invariant 2b — the current item version is closed and superseded, never updated in place.
- Invariant 18 — the contract's snapshotted cadence, anchor, proration and deposit are
  untouched. `rate_change_notice_days` is itself a signing snapshot.
- Invariant 14 — `contract.rate_scheduled`, `contract.rate_amended`,
  `contract.rate_cancelled` as machine keys.
- Notices are append-only, consistent with the notes and activity conventions.

## Acceptance criteria

- [ ] Scheduling writes one closed version, one future-dated version and one notice row, in
      one transaction.
- [ ] Scheduling inserts one `scope='contract'` price owned by the item's `unit_class_rate`,
      referenced by the new item version.
- [ ] The inserted price has no effective window (CHECK-enforced).
- [ ] No `unit_class_rates` row and no catalogue price is created or modified; the Rates
      matrix is unchanged.
- [ ] No existing `prices` row is updated.
- [ ] `Contract::itemsOn(today)` returns the old amount; `itemsOn(effectiveDate)` returns the
      new one.
- [ ] An effective date inside the notice period is rejected with 422 and `required_by`.
- [ ] The short-notice override succeeds and records its reason on the notice and the activity.
- [ ] Amending a pending change writes a further version rather than updating the pending row.
- [ ] Cancelling reopens the previous version and is rejected once the date has passed.
- [ ] Tax is re-resolved and snapshotted on the new version.
- [ ] Insurance items can be re-priced by the same endpoint.
- [ ] Pending changes display inline on the Line items card with amount, date and delta.
- [ ] Seeder produces a contract with a pending future rate change and one with a historical
      applied change.
- [ ] `10-open-decisions.md` records bulk rate changes as deferred.

## Tests required

| Test | Asserts |
|---|---|
| `RateChangeTest::schedules_future_version` | Both versions, correct windows |
| `RateChangeTest::creates_contract_scoped_price` | Owned by the class rate, no window |
| `RateChangeTest::creates_no_junction_row_or_catalogue_price` | Rates matrix unchanged |
| `RateChangeTest::rates_matrix_excludes_contract_prices` | `scope` filter applied |
| `RateChangeTest::items_on_returns_correct_amount_by_date` | Before and after effective date |
| `RateChangeTest::rejects_inside_notice_period` | 422 with `required_by` |
| `RateChangeTest::short_notice_override_records_reason` | Override path |
| `RateChangeTest::amend_supersedes_rather_than_updates` | Pending row unchanged |
| `RateChangeTest::cancel_reopens_previous_version` | `effective_to` back to null |
| `RateChangeTest::cancel_rejected_after_effective_date` | Guard fires |
| `RateChangeTest::tax_resnapshotted_on_new_version` | Uses catalogue version at effective date |
| `RateChangeTest::insurance_item_repricing` | Not unit-only |
| `RateChangeTest::notice_row_written_with_required_by` | Audit trail |
| `RateChangeTest::forbidden_from_ended_contract` | Transition guard applies |
