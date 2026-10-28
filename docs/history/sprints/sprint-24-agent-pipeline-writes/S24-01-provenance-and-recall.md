# S24-01 — Provenance, hold uniqueness, recall

**Repo:** `unit-hq-api`
**Depends on:** `S24-00`
**Blocks:** `S24-04`, `S24-05`

## Goal

Be able to answer, in one query: *which offers and reservations did an agent
create, and can I undo the last hour of them?* Land the columns before there is
data to backfill — the D3 reasoning from `10-open-decisions.md`, applied here.

## Migrations

### `offers` — additive

| Column | Type | Notes |
|---|---|---|
| `source` | string, not null, default `operator` | `operator` \| `public_link` \| `ai_agent` \| `automation` |
| `ai_agent_id` | FK → `ai_agents`, nullable, restrict on delete | set only when `source = ai_agent` |

### `reservations` — additive

Same two columns, same semantics.

Backfill: none. The default handles every existing row, and every existing row
*was* operator-created. Do not write a backfill migration.

`CHECK (source <> 'ai_agent' OR ai_agent_id IS NOT NULL)` on both tables. Portable
expression — SQLite honours it on create, so the in-memory test path enforces it
too.

Indexes: `(source, created_at)` on both. That is the recall query's index.

**Enum:** `App\Enums\PipelineSource`. Mirrors the existing `ContactSource`
precedent (`contacts.source = ai_agent` is already how an agent-created contact
is marked) — reuse the vocabulary, do not invent a parallel one. If
`ContactSource` already carries exactly these four cases, extend it rather than
adding a second enum; check before creating.

### `reservations` — active hold uniqueness

**This is an open design question, not a settled instruction.** The rule we want
is *one active hold per (contact, site, unit_class)*, so a looping agent cannot
hold ten units for one prospect. The table stores `unit_id`, not the triple, so
a plain partial unique index cannot express it.

Options, in the order to try them:

1. **In-transaction check** inside `ReservationCreation::create`, under the
   existing `lockForUpdate` on the candidate unit — plus a supporting index
   `(contact_id, status)`. Correct under the lock we already hold, no schema
   contortion. **Recommended for S24.**
2. Postgres partial unique index over a join-free denormalisation
   (`site_id`, `unit_class_id` copied onto `reservations`). Real constraint, but
   denormalised state that must be kept in sync — invariant 4 territory. Only if
   (1) proves insufficient.
3. Exclusion constraint. Overkill; do not.

Take option 1. Write the reasoning into the PR and add the rejected options to
`10-open-decisions.md` under *Undecided* so it is not re-litigated in six months.

The check is scoped: it applies **only when `source = ai_agent`**. An operator
holding three units for one indecisive customer is legitimate business, and this
sprint must not change operator behaviour.

## `agents:recall` command

```
php artisan agents:recall --agent=sales --since=1h [--dry-run] [--offers] [--reservations]
```

The kill-switch's second half. `AGENTS_ENABLED=false` stops new turns; this
undoes what already happened.

- Offers: `status = expired`, `expires_at = now()`. **Never delete** — an offer
  token may already be in a prospect's inbox and the public route must resolve
  to an expired offer, not a 404.
- Reservations: `status = cancelled` and release the `unit_holds` row through
  the same path a normal cancellation uses. Find it; do not write a second
  release.
- Refuses to touch any row where `source <> 'ai_agent'`.
- Refuses to touch an offer that has been **accepted** — that reservation may
  have become a contract. Report those instead, loudly, with ids.
- `--dry-run` defaults to **true**. Committing requires `--dry-run=false`.
- Tier-1 `SystemEvent` `agents.recall.started` / `.committed` with counts.
- `RecordsActivity::core` per row with the recall reason.

Not a route. Not a panel button. An operator runbook command — document it in
`docs/ops/`.

## Entry-point changes

`OfferCreation::create` and `ReservationCreation::create` gain the source from
`LeasingActor`: `LeasingActor::agent()` implies `source = ai_agent` +
`ai_agent_id`; `employee()` implies `operator`. Do not pass `source` as a caller
argument — deriving it from the actor makes it impossible to lie about.

The public offer-accept route (`offer-options/{offerOption}/select`) is anonymous
and creates a Reservation. That reservation's source is `public_link`, not the
source of its parent offer. `OfferAcceptance` needs a `LeasingActor::publicLink()`
factory or equivalent — decide and document which.

## Tests

- `PipelineSourceTest` — CHECK rejects `source = ai_agent` with a null agent id
  on both tables, at the database level.
- `ReservationCreationTest` — agent actor creating a second active hold for the
  same (contact, site, unit_class) is rejected; the same double-create by an
  employee actor succeeds.
- `AgentsRecallCommandTest` — dry-run writes nothing; commit expires offers and
  cancels reservations and releases holds; operator-sourced rows untouched;
  accepted offers reported and skipped; `SystemEvent` pair emitted.
- `OfferAcceptanceTest` — public accept stamps `public_link`, not the offer's source.

## Acceptance

- [ ] `SELECT * FROM offers WHERE source = 'ai_agent'` answers the provenance
      question with no join to `activity_log`.
- [ ] Neither table can hold `source = 'ai_agent'` with a null `ai_agent_id`.
- [ ] The active-hold rule applies to agent-sourced reservations only, and
      existing operator tests are unchanged.
- [ ] `agents:recall --dry-run` is the default and prints a per-row plan.
- [ ] The rejected uniqueness options are written into `10-open-decisions.md`.
