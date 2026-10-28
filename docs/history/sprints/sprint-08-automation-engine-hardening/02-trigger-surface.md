# S08-02 — Trigger surface: delinquency & payments

## Context

S09's playbooks enrol on events the trigger system can't see yet: a delinquency case
opening (debt process start), curing (exit), a payment landing, an autopay failing, a
deal going quiet (lead chase). This task extends the trigger surface to the S06/S07
domains and — the subtle part — proves triggering works from **queue and scheduled
contexts**, where most of these events are born.

## Scope

**In:** `HasAutomationTriggers` on `Delinquency`, `AutopayAttempt`, `Payment` (created
only), morph-map entries, trigger-domain filter options, queue-context causer semantics,
suppression-boundary tests.
**Out:** the playbook *semantics* on these triggers (S09), `trigger.email_received`
(still blocked on inbound webhooks — comms phase), time-based "deal quiet for N days"
triggers (that's a schedule trigger + condition, already possible — document the recipe
instead of building a new trigger type).

## Behaviour

- **Delinquency**: `object_created` (= case opened — the debt-process enrolment event)
  and `object_updated` (cure = `cured_on` transitioning null→date; the update event
  carries the dirty diff, so a condition `cured_on is_not_empty` is the exit signal —
  document this recipe for S09). Payload snapshot includes computed `days_overdue` and
  `overdue_base` at dispatch (**snapshot at dispatch**, per the 01 rule — conditions on
  them must not drift with live state).
- **AutopayAttempt**: `object_created` and `object_updated` (failure lands as the status
  update). The "retry failed autopay after 3 days" ambition from S06 becomes: trigger on
  failed attempt → wait 3d → guard(still owing) → create manual-retry task — a *recipe*,
  listed in the S09 seed material, zero engine code.
- **Payment**: `object_created` only — payments are append-only; an update trigger on an
  immutable table is a lie. This is also the lead-chase "they paid" style exit input,
  though S09's debt exit will prefer the Delinquency cure event.
- **Causers in workers.** These events fire inside jobs where invariant 25's rule bites:
  causer is stamped at dispatch, possibly **null** (engine-originated). Verify: (a) null
  causer flows through run records without blowing up resource rendering, (b)
  `AutomationContext` suppression still holds — an automation's `create_object` writing a
  Task must not re-trigger, but the *delinquency engine* writing a case **must** trigger
  (it is not automation-originated; add the positive test, it's the one nobody writes),
  (c) request-id correlation restores in the run's Tier-1 events.

## Panel surface

Automation editor trigger picker gains the new objects under a "Billing" domain group;
condition field lists for them come from the same `FilterableFields`-style whitelist
idiom (never raw columns — the advanced-filters rule applies to trigger conditions too;
whitelist: delinquency days/base/policy/cured, attempt status/codes, payment
amount/method). i18n `automations.triggers.billing.*`.

## Acceptance criteria

- [ ] Case open (engine-written, queue context, null causer) fires a matching automation;
      suppression negative test (automation-written task doesn't) and positive test
      (engine-written case does) both green.
- [ ] Cure fires `object_updated` with the dirty diff enabling the documented exit
      recipe; payload days/base are dispatch-time snapshots.
- [ ] Failed autopay and created payment trigger; payment has no update trigger
      (asserted absent).
- [ ] Condition fields for the three objects come from whitelists; unknown fields
      rejected by the tree validator.
- [ ] Trigger picker shows the Billing group; recipes documented in
      `docs/automation-conditions.md` appendix (S09 reads them).

## Tests required

| Test | Asserts |
|---|---|
| `BillingTriggerTest::case_open_fires_from_queue_null_causer` | The positive suppression case |
| `BillingTriggerTest::cure_diff_enables_exit_recipe` | Dirty-payload semantics |
| `BillingTriggerTest::snapshot_payload_fields` | No live drift |
| `BillingTriggerTest::payment_created_only` | Append-only honesty |
| `TriggerWhitelistTest::unknown_fields_rejected` | Filters rule extended |
