# S10-04 — Sinch & Aircall adapters

## Context

The two providers from the original brief not yet wired. Sinch is a full SMS peer to
Twilio (send + delivery events + inbound) proving the adapter seams are real; Aircall
is **receive-only this sprint** — account config + call webhooks logging calls as
messages, so S12's click-to-call builds on rails instead of laying them.

## Scope

**In:** Sinch adapter (all three capability interfaces), Aircall account type + inbound
call events → call messages, registry/settings updates, per-adapter payload fixtures.
**Out:** Aircall outbound dialing (S12), call recordings storage (store the provider's
recording URL + metadata only; media mirroring is an S12 decision), Sinch/Twilio number
provisioning UI.

## Behaviour

**Sinch.** Implement `ProviderAccount` (credential fields: service plan id / API token /
region per their REST SMS API — verify against current docs at implementation, the
Verifactu rule applies to third-party APIs too), `SendsSms`, `ReportsDeliveryEvents`
(delivery report callbacks → normalized events into 01's pipeline, derived-key note if
their ids prove unstable), inbound MO callbacks → 02's inbound path (the STOP keywords
from 03 must work identically — test it on this adapter explicitly). Uncomment the
registry entry; `SmsSender` needs zero changes if the seams held — that absence of diff
is an acceptance criterion.

**Aircall.** New `Channel::Call` becomes `isImplemented()`. Account: API id + token
(credential discipline as ever), webhook (Aircall configures per-integration; pasteable
URL pattern + signature/token verification per their scheme). Events consumed:
`call.created`/`call.answered`/`call.ended` (+ voicemail) → one **call message** per
call on the contact's call thread (`channel_key` = the external number; matching via
`contact_channels` phone, unmatched → 02's triage): body = synthesized summary
(direction, duration, outcome, agent), `source_ref` = full call metadata + recording
URL. Interaction written (the existing call-shaped timeline rows finally get a real
writer). Missed inbound call → unread increment (an operator should see it in S11's
inbox); answered calls don't.

**Settings.** The provider grid now shows all four with correct capability-driven UI
(the `AutoRegistersWebhooks`-conditional button logic already handles this — verify
Sinch/Aircall render their pasteable URLs). Sender identities: SMS from-number per site
extends to Sinch accounts unchanged.

## Acceptance criteria

- [ ] Sinch send lands a message with provenance; its delivery report flips state
      through 01; its inbound MO threads through 02; STOP suppresses through 03 —
      the full pipeline on a second SMS provider **with zero sender/pipeline diffs**
      (assert by architecture test: changes confined to the adapter + registry).
- [ ] Switching the active SMS provider Twilio↔Sinch mid-day: old messages still
      reconcile via their stored `communication_account_id` (the provenance promise
      kept), new sends use the new provider.
- [ ] Aircall inbound + missed + voicemail calls create call messages with correct
      unread behaviour; unmatched numbers triage; recording URL stored not mirrored.
- [ ] All four providers configurable end-to-end from Settings with webhook state
      visible; capability-driven buttons correct.
- [ ] Recorded payload fixtures: Sinch delivery + MO, Aircall call lifecycle.

## Tests required

| Test | Asserts |
|---|---|
| `SinchAdapterTest::full_pipeline_zero_core_diffs` | The seam proof |
| `ProviderSwitchTest::midday_swap_reconciles_old` | Provenance promise |
| `AircallTest::call_lifecycle_to_messages` | Incl. missed/voicemail/unread |
| `AircallTest::unmatched_number_triage` | 02 integration |
| `AdapterParseTest::{sinch,aircall}_fixtures` | Real payloads |
