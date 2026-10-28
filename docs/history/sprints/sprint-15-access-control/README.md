# Sprint 15 — Access Control

## Goal

The gate and the unit door obey the ledger: a tenant's access exists because their
occupancy and standing say it should, disappears the moment delinquency says it
shouldn't, and returns when they pay — through an **adapter interface with Sensorberg
first** (the standing decision), driven by a **declarative reconciliation engine**, with
S07's reserved `revoke_access` step finally activated.

## The architecture in one paragraph

Access is **derived state, projected outward**. Who-may-open-what is a pure computation
over facts that already exist — occupancies (S01), overlock holds (S07), contract status
(S02) — plus one new fact this sprint adds (access suspensions). The provider's grant
list is a *cache of that computation* living on someone else's server, and the sync
engine's whole job is reconciling it: compute desired, diff against what we've applied,
converge. Never imperative grant/revoke calls scattered through business code — business
code writes facts; the engine projects them. This is invariant 5 extended across an API
boundary, and it's what makes a second provider an adapter instead of a rewrite.

## Overlock vs. suspension vs. revocation (vocabulary, fixed now)

| Concept | Fact | Physical | Digital |
|---|---|---|---|
| **Overlock** (S07) | `unit_holds` type `overlock` | Operator's second lock | Sync engine denies the unit door *as a consequence* |
| **Access suspension** (new) | `access_suspensions` row per contract | None | Sync engine denies everything for the contract |
| `revoke_access` step | Writes a suspension | — | The softer escalation rung: no walk to the unit required |

A policy may ladder: day 8 `revoke_access` (they can't get in — most tenants call that
day), day 12 `place_overlock` (the physical statement). Cure lifts both per the existing
auto-release flag pattern.

## Exit criteria

- [x] Units and site gates map to provider access points; a signed move-in grants gate +
      unit access without human action; vacate/transfer revoke/move it.
- [x] The `revoke_access` policy step executes; the tenant's credential stops working at
      every point; payment restores it the same afternoon (the S07 cure-hook latency).
- [x] Overlock placement denies the unit door digitally as a side effect of the existing
      hold — no new call sites in delinquency code.
- [x] `access:sync --dry-run` prints the full desired/actual diff; drift injected at the
      provider is detected and converged; provider downtime degrades to queued
      convergence, never lost state.
- [x] Denied-entry events during a suspension land on the contact timeline (the dispute
      evidence S07's audit posture always wanted).
- [ ] A `FakeSecondProvider` round-trips with changes confined to adapter + registry.

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Access domain model & desired state](./00-access-domain-model.md) | 1 day |
| 01 | [Provider accounts & Sensorberg adapter](./01-provider-and-sensorberg.md) | 1.5 days |
| 02 | [Reconciliation engine](./02-reconciliation-engine.md) | 1.5 days |
| 03 | [Delinquency & lifecycle activation](./03-delinquency-activation.md) | 1 day |
| 04 | [Surfaces & events](./04-surfaces-and-events.md) | 1 day |

## Risks

**Verify Sensorberg's actual API early — it shapes the credential model.** Their
platform is app-invite-centric (tenant installs their app, invited by email); PIN/code
support varies by hardware. Task 01's `credentialModes()` capability lets the adapter
declare what it can do, and the UI adapts — but if the client's hardware is
PIN-keypad-only, the invite flow is dead weight. One sandbox call before building the
grant UX; the standing third-party rule, with teeth this time.

**The provider is slow and sometimes down; the ledger is neither.** All provider calls
live in the queued engine with per-grant failure isolation (the S05 posture). A payment
restoring access must *feel* immediate: the cure hook nudges sync within minutes, and
the panel shows `applying…` honestly rather than lying about convergence.

**Never trust our cache for safety decisions.** `access_grants` records what we
*believe* we applied; the drift check exists because reality wins. Denied-when-should-be-
granted is an incident surface (attention chip); granted-when-should-be-denied is a
louder one (Tier-3 — a delinquent tenant entering after revocation is the case the
client bought this feature for).
