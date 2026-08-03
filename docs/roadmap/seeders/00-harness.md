# Demo-00 — Harness & synthetic-event injection

## Context

The machinery every later task drives: a clock that steps, a queue that runs
synchronously in-step, and injectors that feed fabricated external events through the
real idempotent processors. Get this right and tasks 01–03 are *scripts*; get it
wrong and they're a re-implementation of the app.

## Scope

**In:** `Database\Seeders\Demo\` namespace, `DemoClock`, `DemoWorld` context object,
the injector suite (Stripe, delivery, inbound comms, e-sign, access), fake-provider
account bootstrap, the `demo:seed` command + guards. **Out:** journeys (01),
population (02/03), verification (04).

## Behaviour

**Command.** `php artisan demo:seed {--fresh}` — refuses on production env (hard
guard), `--fresh` runs `migrate:fresh --seed` (the base seeders: countries, layouts,
sites/classes/rates world, templates, playbooks — the *stage*, empty of actors) then
the demo pipeline. Prints phase timings (the runtime lever's instrument).

**Clock.** `DemoClock::run(CarbonImmutable $from, $to, callable $eachDay)` —
`setTestNow` per day at a fixed hour per site-relevant tz handling (jobs already use
SiteClock; the harness sets one canonical instant per day and lets the jobs resolve).
Inside each tick, `eachDay($date, $world)` executes scripted events, then the
standard jobs in schedule order (activate → billing → delinquency → sync → playbook
resume sweep — the real ordering guarantees). Queue: `sync` driver for the run
(dispatched jobs execute inline in-step; `afterCommit` hooks fire — verify with a
spike, it's the harness's one subtle bit: `DB::afterCommit` inside the seeder's outer
transaction… **no outer transaction**, precisely for this; each day commits).

**Injectors** — each fabricates a provider-shaped payload and calls the *real*
processing entry (controller-level where signature verification would block →
enter at the post-verification job with a constructed event row, documented as the
sanctioned bypass since fakes hold no real secrets):

- `StripeInjector::paymentSucceeded(intent…)` / `paymentFailed(code)` /
  `setupSucceeded(card)` — against the seeded fake provider accounts (S06's
  connected-fake bootstrap reused).
- `DeliveryInjector::event(message, status)` — bounce/open/deliver via the S10
  pipeline (per-adapter payload fixtures reused as templates).
- `InboundInjector::email(from, thread?, body, attachments?)` / `sms` /
  `whatsapp` — through the S10 inbound path; threading/triage/window all real.
- `ESignInjector::viewed|signed|declined(envelope)` — through the orchestrator's
  event entry; the fake adapter serves the signed bytes.
- `AccessInjector::doorEvent(point, contact?, granted|denied)` — S15 event path.

**World object.** `DemoWorld` carries the registries the scripts reference by
handle: `$world->contact('marcus')`, `$world->site('camden')`, `$world->remember(
'marcus.contract', $c)` — string handles, because journeys (01) read like scripts,
not ID bookkeeping.

## Acceptance criteria

- [ ] `demo:seed --fresh` runs the empty-stage pipeline end-to-end (no journeys yet)
      in seconds; production guard refuses; timings print.
- [ ] Clock spike proves: a contract created day 1 activates on its move-in day,
      bills on its anchor, and the `afterCommit` cure-hook fires in-step (the one
      subtle bit, asserted).
- [ ] Each injector round-trips one event through its real processor with the fake
      accounts (five smoke tests).
- [ ] No raw inserts past the model layer anywhere in the namespace (grep-as-test —
      the staging temptation, forbidden structurally).

## Tests required

`DemoHarnessTest::{clock_activation_billing_cure, five_injector_smokes,
no_raw_inserts_grep, production_guard}`.
