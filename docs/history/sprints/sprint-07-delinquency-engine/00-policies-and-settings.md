# S07-00 — Policies & settings

## Context

The ladder is data: a named policy with ordered steps ("day 5: fee 10%; day 10: overlock;
day 14: final-demand notice"), assigned per site — because a UK site and a Spanish site
escalate differently by *contract terms*, not by code. This task is schema + validation +
the settings editor; nothing executes yet.

## Scope

**In:** `delinquency_policies` + `delinquency_policy_steps`, site assignment, step-action
catalogue with `revoke_access` reserved (S16 idiom — value exists, execution rejected),
Settings UI, seeded default policy per country flavour.
**Out:** execution (02), per-contract policy overrides (site-level is v1; record in
`10-open-decisions.md`), recurring/repeating steps (one-shot per case in v1, same entry).

## Schema changes

```sql
CREATE TABLE delinquency_policies (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    auto_release_overlock BOOLEAN NOT NULL DEFAULT true,
    archived_at TIMESTAMP NULL,
    created_at TIMESTAMP, updated_at TIMESTAMP
);

CREATE TABLE delinquency_policy_steps (
    id BIGSERIAL PRIMARY KEY,
    delinquency_policy_id BIGINT NOT NULL REFERENCES delinquency_policies(id),
    offset_days SMALLINT NOT NULL,            -- from oldest unpaid due date, site-local
    action VARCHAR(32) NOT NULL,
        -- assess_late_fee | place_overlock | record_notice | create_task | revoke_access*
    params JSONB NOT NULL DEFAULT '{}',
    sort SMALLINT NOT NULL,
    created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE UNIQUE INDEX dps_order_idx ON delinquency_policy_steps (delinquency_policy_id, sort);

ALTER TABLE sites ADD COLUMN delinquency_policy_id BIGINT NULL
    REFERENCES delinquency_policies(id);   -- NULL = delinquency disabled for the site
```

**Params per action (validated shapes, reject unknown keys):**

| Action | Params |
|---|---|
| `assess_late_fee` | `{ "type": "flat"\|"percent", "amount": "10.00"?, "percent": "10.00"?, "cap_per_case": "50.00"? }` |
| `place_overlock` | `{}` |
| `record_notice` | `{ "notice_type": "payment_reminder"\|"overdue"\|"final_demand"\|"retention" }` |
| `create_task` | `{ "title_key": "...", "urgent": bool }` — assignment rules stay S09's problem; v1 assigns run-causer-less to the first employee (the existing automation default) |
| `revoke_access` | reserved — **create/update of a step with this action returns 422 naming S16**, exactly the S01 overlock idiom |

Validation beyond shapes: offsets ≥ 0, unique per policy (two steps same day allowed only
with different actions — enforce `(policy, offset_days, action)` unique), percent fees
require `percent`, flat require `amount`, money as strings.

**Policy edits and open cases:** steps already executed are history (append-only case
records, task 01); edits affect only *future* evaluation. No snapshotting onto contracts —
deliberately unlike invariant 18: the ladder is operational conduct, not contracted
billing terms, and operators must be able to soften escalation for everyone at once.
State this reasoning in the settings help text and in `09` as a scoped exception note,
**except** late-fee `type/amount/percent`, which *are* typically contractual — v1 accepts
the live-policy reading with the tradeoff documented in `10-open-decisions.md`
(fee-terms snapshotting is the known follow-up if a client's contracts pin exact fees).

## Panel surface

Settings → **Late fees & delinquency** (the nav slot has waited since the first mockups):
policy list; editor = ordered step rows (day offset, action select — `revoke_access`
visible but disabled "Requires access-control integration (S16)", params inline per
action, drag-free arrow reorder per the object-customization precedent); site assignment
select on the site form + a per-policy "used by N sites" count; archive-only with
in-use guard. Fiscal flags surfaced read-only with gestor notes: `fiscal.late_fee_tax`
(default 0%), `fiscal.invoice_late_fees` (default false). i18n `settings.delinquency.*`;
es: *Recargos e impagos*, late fee → *Recargo por demora*.

## Acceptance criteria

- [ ] Policies + steps CRUD with all param validations; `revoke_access` rejected with the
      S16 message; archive-only; in-use policies un-archivable.
- [ ] Site assignment nullable = disabled; site form shows it.
- [ ] Seeder: "ES standard" (day 5 fee 10% cap 50, day 8 overdue notice, day 12 overlock,
      day 20 final demand + urgent task) and "UK standard" variant; sites assigned;
      one site left unassigned (disabled-path fixture).
- [ ] `09` exception note + `10-open-decisions.md` entries (per-contract override,
      recurring steps, fee-terms snapshot) recorded.

## Tests required

| Test | Asserts |
|---|---|
| `PolicyTest::param_shapes_per_action` | Table-driven valid/invalid |
| `PolicyTest::revoke_access_reserved` | 422 + message key |
| `PolicyTest::offset_action_uniqueness` | Same-day different-action allowed |
| `PolicyTest::archive_guards` | In-use refusal |
