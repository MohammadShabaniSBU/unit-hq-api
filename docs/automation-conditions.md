# Automation condition semantics

Authoritative rules for `App\Support\Automation\ConditionEvaluator`.
Branches, run guards (S08-00), trigger pre-filters, and S09 exit conditions all use
this class. The golden matrix in `tests/Unit/Automation/ConditionGoldenTest` cites
these rule numbers — a matrix row change without a matching doc edit is a bug.

## Rule 1 — Type discipline

Comparisons are typed by the **field definition**, never by guessing the runtime value.

| Type | Source | Compare |
|---|---|---|
| `text` / `email` | `FilterableFields` / attr text | Lexical string |
| `number` | `FilterableFields` / attr number | Numeric (`bccomp` scale 8 on castable decimals) |
| `money` | Evaluator money-key map (see Rule 6) | `bccomp` on decimal strings (invariant 10) |
| `date` | Date-only columns / attr date | Site-local calendar day (`Y-m-d` in context timezone) |
| `datetime` | `*_at` timestamps mapped from filterable `date` | Instant (UTC-normalized) |
| `boolean` | Native / attr boolean | Strict boolean after cast (`true`/`false`/`1`/`0`/`"true"`/`"false"`) |
| `select` | Enum / attr select | Option id or enum string equality |
| `multiselect` | Attr multiselect | List of option ids |

- Native fields resolve via `FilterableFields` for the condition's entity type.
  Filterable type `date` whose key ends with `_at` is treated as `datetime`; other
  `date` keys are date-only.
- Custom attributes use keys `attr:{id}` and resolve via `AttributeDefinition.type`.
- Unregistered native keys (no field definition) are treated as `text`.
- A value that **fails its field's type cast** → condition is **false** and a
  **warning** is recorded on the result. Never throw mid-run.
- Deleted / unknown `attr:{id}` → **false + warning** (never throw).

## Rule 2 — Null / missing / empty trichotomy

Three distinct cases:

| Case | Representation |
|---|---|
| Null | PHP `null` in the values bag |
| Missing | Field key absent from the bag — **behaves as null** |
| Empty string | `""` — a real value, not null |

SQL-ish null for `equals` / `not_equals` (surprising; must stay written down):

- `null equals x` → **false** (including `null equals null`)
- `null not_equals x` → **false** (including `null not_equals null`)
- Either side null → both operators false

Sanctioned emptiness checks: `is_empty` / `is_not_empty`.

`is_empty` is true for: `null`, missing (≡ null), `""`, `[]`.
Empty string is **not** null for `equals` (`"" equals ""` → true).

## Rule 3 — Collections

| Operator | Behaviour |
|---|---|
| `in` | Actual is a member of the expected list. Empty option list → **false**. |
| `not_in` | Actual is not a member. Empty option list → **true** (vacuous). Null/missing actual → **false** (SQL-ish; use `is_empty`). |
| `contains` / `not_contains` | **String substring only** on text fields. Not used for multi-select membership. |
| Multi-select membership | Use `in` / `not_in` against the selected option-id list (actual list contains expected scalar, or expected list overlaps — see operator table). |

For multiselect + `in`: passes if **any** selected option id is in the expected list
(overlap). For `not_in`: passes if there is **no** overlap (and actual is non-null).

Wrong-type actual for collection ops → false + warning.

## Rule 4 — Nesting (AND / OR / NOT)

- Groups: `{ "logic": "and"|"or"|"not", "conditions": [ … ] }` (`op` accepted as alias of `logic`).
- Nested groups allowed to arbitrary depth (a child with `logic`/`op` + `conditions` is a group).
- `not`: evaluates the first condition/group and negates the boolean; additional siblings are ignored (warn if more than one).
- Empty `conditions` → true (except `not` with empty → true, negation of nothing / vacuous).
- **Short-circuit**: AND stops on first false; OR stops on first true. Warnings from
  **unevaluated** sides must not appear (evaluation is side-effect-free w.r.t. warnings).
- Short-circuit order among siblings is an implementation detail otherwise.

## Rule 5 — Snapshot vs live

Same evaluator, explicit `ConditionSource` on `ConditionContext` — never ambient.

| Caller | Source | Values |
|---|---|---|
| Trigger pre-filter | `snapshot` | Dispatch-time model attributes (+ custom attrs) |
| `logic.branch` | `snapshot` | `trigger_payload` (natives + `custom_attributes`) |
| Run guard | `live` | Fresh subject row + live EAV |

After a wait resume, branch conditions still read the **stored** snapshot — never
re-fetch the model for branch/trigger trees. Guards intentionally re-read live.

## Rule 6 — Money

Amount fields compare via bcmath on string decimals — the only place the evaluator
touches invariant 10. Never `(float)`.

Money keys (evaluator map, overrideable in tests via `ConditionContext::$fieldTypes`):

- `amount`, `unit_amount`, `tax_amount`, `total_amount`, `balance`, `deposit_amount`, `overdue_base`

`"9.50" < "10.00"` is numeric true, not lexical false.

---

## Operator table

| Operator | Aliases | Null/missing | Notes |
|---|---|---|---|
| `equals` | `eq` | false if either null | Typed |
| `not_equals` | `neq` | false if either null | Typed |
| `contains` | | false if either null | Text substring |
| `not_contains` | | false if either null | Text substring |
| `starts_with` / `ends_with` | | false if either null | Text |
| `is_empty` / `is_not_empty` | | see Rule 2 | |
| `greater_than` / `less_than` | `gt` / `lt` | false if either null | number/money/date/datetime |
| `gte` / `lte` | | false if either null | |
| `in` / `not_in` | | see Rule 3 | Expected must be a list |
| `changed` | | both null → false; one null → true | Uses oldValues; else typed inequality |

Unknown operator → false + warning.

## Field keys

- Native: column name (`first_name`, `status`, …).
- Custom: `attr:{definitionId}` (e.g. `attr:12`).
- Property-update pre-filters omit `field` and evaluate against bag key `__value`.

## Appendix — Playbook recipes (S08-02)

S09 playbooks enrol on these shapes. Trigger payload `days_overdue` / `overdue_base`
are **snapshots at dispatch** (Rule 5) — never re-read live severity for trigger/branch
conditions.

### Debt enrol — case opened

```
trigger.object_created
  objectType: delinquency
  filters: (none, or days_overdue / overdue_base / delinquency_policy_id)
```

Fires when `DelinquencyLifecycle::open` writes a case (often from a queue worker with
**null causer**). That is the debt-process enrolment event.

### Debt exit — case cured

```
trigger.object_updated
  objectType: delinquency
  property: cured_on
  conditions: [{ operator: is_not_empty }]
```

Cure sets `cured_on` null→date; the dirty diff is what `TriggerMatcher` pre-filters on.
Prefer this over payment-created for debt exit.

### Failed autopay → wait → retry task (recipe, zero new engine code)

```
trigger.object_updated
  objectType: autopay_attempt
  property: status
  conditions: [{ operator: equals, value: failed }]
→ logic.wait (3 days)
→ run guard: still owing (live)
→ action.create_object (task: manual retry)
```

`object_created` on `autopay_attempt` is also available (pending attempt lands); failure
usually arrives as the status update.

### Payment landed

```
trigger.object_created
  objectType: payment
  filters: amount / method (whitelist only)
```

Payments are append-only — **no** `object_updated` trigger (rejected at save/activate).
Useful for lead-chase “they paid” exits; debt exit still prefers delinquency cure.

### Quiet deal (no new trigger type)

```
trigger.schedule (daily)
→ logic.branch / run guard on deal inactivity
  (e.g. last activity older than N days — live condition)
```

Do not invent a `deal_quiet` trigger; schedule + condition already covers it.
