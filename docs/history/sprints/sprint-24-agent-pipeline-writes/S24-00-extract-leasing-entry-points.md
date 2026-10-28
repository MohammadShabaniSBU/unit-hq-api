# S24-00 — Extract leasing transactional entry points

**Repo:** `unit-hq-api`
**Depends on:** —
**Blocks:** everything else in S24

## Goal

One implementation of offer creation, offer acceptance and reservation creation,
reachable from a controller, a Copilot tool, a customer-facing agent tool and a
future automation node. **No behaviour change.** This task ships alone and is
mergeable on its own merit.

Today the same transaction exists four times and has already drifted (see the
sprint README). Every S24 feature task assumes this is done; without it the
feature is a fork.

## Namespace

`App\Support\Leasing\` — same tier as `App\Support\Billing\ContractBilling`,
which is the existing precedent for extracted multi-step orchestration. This is
**not** an `app/Services/` layer and must not become one: no interfaces, no
container bindings, no constructor injection of repositories. Static or
`new`-able final classes holding the transaction, exactly as `ContractBilling`
does.

```
App\Support\Leasing\
├── OfferCreation.php
├── OfferAcceptance.php
├── ReservationCreation.php
├── ReservationHolds.php        // moved out of Http/Controllers/Concerns
└── LeasingActor.php            // causer abstraction
```

## `LeasingActor`

Every entry point needs a causer for `RecordsActivity` and for `unit_holds.created_by`.
Today that is always an `Employee`. After S24 it may be an `AiAgent`.

```php
final readonly class LeasingActor
{
    private function __construct(
        public ?Employee $employee,
        public ?AiAgent $agent,
    ) {}

    public static function employee(Employee $employee): self;
    public static function agent(AiAgent $agent): self;
    public static function system(): self;          // billing:run, sweeps
    public static function publicLink(): self;      // public offer-accept route

    public function causer(): ?Model;               // for RecordsActivity
    public function employeeId(): ?int;             // for unit_holds.created_by
}
```

`AiAgent` is already a real Eloquent model precisely so it can be an
`activity_log` causer (D-AI-4). Do **not** add an `ai_agent` morph-map alias —
same reasoning as `employee` (invariant 15; see `10-open-decisions.md`).

`unit_holds.created_by` is an employee FK. For an agent actor it stays **null**
and the agent is recorded in the activity properties. Do not widen that column
to a morph in this task.

## `OfferCreation`

Lift the body of `OfferController::store`'s `DB::transaction` closure verbatim.

```php
public static function create(
    array $attributes,          // deal_id, contact_id, status?, expires_at, sent_at?, …
    array $options,             // [{unit_class_rate_id, discount_id?, label, description?, display_order}]
    array $customAttributes,    // AppliesCreateAttributes payload
    LeasingActor $actor,
): Offer
```

Behaviour to preserve exactly:

- `token` minted with `Str::random(64)` when absent (invariant 6 — crypto-random,
  never the PK).
- each option gets `unit_id = Unit::resolveUnitIdForRate($unit_class_rate_id)`
  at create time — the unit is pinned **before** acceptance.
- `AppliesCreateAttributes::apply(AttributeEntityType::Offer, …)`.
- the whole thing inside one `DB::transaction`.

Validation stays in the controller's `$request->validate()`. The entry point
assumes validated input and asserts invariants with `ValidationException` only
where the controller cannot check them (row-level races).

## `OfferAcceptance`

Lift `OfferOptionController::select`. This one carries the trickiest logic in the
file and must not be paraphrased.

```php
public static function accept(OfferOption $offerOption, LeasingActor $actor): Offer
```

Preserve, in order:

1. pre-transaction expiry / already-accepted checks and the
   `SystemEvent::record('offer.accept.started', …)`.
2. inside the transaction: `Offer::lockForUpdate()` then **re-check** expiry and
   status. The double-check is the concurrency guard — do not collapse it.
3. re-validate the pinned `offer_options.unit_id` with
   `Unit::lockForUpdate()` + `isAvailableOn(SiteClock::today($site))`; fall back
   to `Unit::resolveUnitIdForRate()` when it has gone.
4. write `selected_at`, flip `offers.status = accepted` + `accepted_at`.
5. resolve `unitClassRate.price->id` or throw.
6. create the `Reservation` with `expires_at` from `LeasingSettings`
   (`defaultReservationExpirationValue` / `…Unit`) — move
   `OfferOptionController::reservationExpiresAt()` into
   `ReservationCreation::defaultExpiry()` and call it from both.
7. `ReservationHolds::write(...)`.
8. `SystemEvent::record('offer.accept.committed', …)` and the two
   `RecordsActivity::core('offer.accepted', …)` calls (offer **and** contact).

## `ReservationCreation`

Lift `ReservationController::store`'s closure.

```php
public static function create(
    int $siteId,
    int $unitClassId,
    int $contactId,
    ?int $dealId,
    ?int $unitId,               // null = auto-pick
    ?Carbon $expiresAt,         // null = defaultExpiry()
    ?int $offerOptionId,
    ?ReservationStatus $status,
    array $customAttributes,
    LeasingActor $actor,
): Reservation

public static function defaultExpiry(): Carbon
```

Preserve the two-branch unit selection **and its comment** — explicit `unit_id`
locks without the availability scope so occupied/held units surface
`OccupancyGuard` / `HoldGuard` 422s; auto-pick uses `availableOn(SiteClock::today($site))`
(D8). Preserve the deal/site agreement checks, the `latestRate->price` resolution,
`price_id` pinning, and `RecordsActivity::core('reservation.created', …)`.

`status` keeps the omit-rather-than-null trick: the column has a DB-level default
that only applies when the key is absent from the insert. Both existing copies
comment this; keep the comment.

## Call sites to collapse

| File | Change |
|---|---|
| `Http/Controllers/OfferController::store` | validate → `OfferCreation::create` → respond |
| `Http/Controllers/OfferOptionController::select` | → `OfferAcceptance::accept` with `LeasingActor::publicLink()` |
| `Http/Controllers/ReservationController::store` | validate → `ReservationCreation::create` |
| `Http/Controllers/ReservationController::convert` | `ReservationHolds::release` |
| `Http/Controllers/Concerns/WritesReservationHolds` | deprecated write-only shim delegating to `ReservationHolds` |

**Copilot tools are out of scope for S24-00** (zero diff under `app/Ai/`). They
stay on the `LeasingEntryPointParityTest` allowlist until a later sprint.

Finding (not a change): `OfferController::store` records no `SystemEvent`, so
there was never an `offer.create.*` event for Copilot `CreateOffer` to be
"missing." Do not invent one.

The Copilot behaviour changes originally listed here (`AppliesCreateAttributes`
on `CreateOffer`; `defaultExpiry()` when `expires_at` is absent on
`CreateReservation`) are deferred with the tools.

Do not silently "fix" anything else. Any other diff you find is a finding to
report, not a change to make.

## Tests

Characterization first — write these against `dev` **before** touching anything,
confirm green, then refactor and confirm still green.

- `OfferCreationTest` — token is 64 chars and not the PK; each option carries a
  pinned `unit_id`; custom attributes applied.
- `OfferAcceptanceTest` — happy path; expired offer rejected; already-accepted
  rejected; **pinned unit gone → a fresh unit is resolved**; no unit available →
  `ValidationException` and nothing committed; partial unique index on
  `offer_id WHERE selected_at IS NOT NULL` still holds under a double accept.
- `ReservationCreationTest` — auto-pick skips occupied and held units; explicit
  `unit_id` on an occupied unit surfaces the guard 422; deal/site mismatch
  rejected; `price_id` pinned from the current rate; `unit_holds` row written.
- `LeasingEntryPointParityTest` — **new, and the point of the task.** No file
  outside `App\Support\Leasing\` creates Offer/Reservation rows except the two
  allowlisted Copilot tools. Forbidden-needle scan is restricted to the three
  controllers. `database/seeders/` is out of scope. Same grep-as-test discipline
  as invariant 43.
- Existing suite green with **zero** changes to assertions. If an existing test
  needs editing, the refactor is wrong.

## Acceptance

- [ ] No file outside `App\Support\Leasing\` contains `Reservation::query()->create`
      or `Offer::query()->create` except the two allowlisted Copilot tools.
- [ ] `WritesReservationHolds` contains no logic and delegates
      `writeReservationHold` to `ReservationHolds` (no `releaseReservationHold`).
- [ ] `POST /api/reservations` stamps `unit_holds.created_by` with the
      authenticated employee (previously null). Activity causer unchanged.
- [ ] `LeasingEntryPointParityTest` green.
- [ ] Full suite green with no assertion edits.
- [ ] No `app/Services/` directory created; no interface or container binding
      introduced for the three new classes.
- [ ] PR body lists the SystemEvent finding (never existed on offer create),
      Copilot left duplicated on purpose, and HTTP `unit_holds.created_by`
      now following `LeasingActor` (activity causer is not a change).
