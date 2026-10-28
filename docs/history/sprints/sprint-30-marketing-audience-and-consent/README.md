# Sprint 30 — Marketing audience & consent

## Goal

Everything a marketing send needs **before** a campaign exists: provable consent,
subscription lists, a preference center, signup forms with double opt-in, saved
segments, and a marketing-only sending lane. Sprint 31 builds campaigns, newsletters
and announcements on top of this; nothing here sends a campaign.

## Why consent comes first

`contact_channels.opted_in` is not marketing consent: `TriageResolver` sets it to
`true` for inbound-created channels and the AI `CreateContactChannel` tool sets it
freely. Under GDPR / LSSI (Spain) we must prove *who* agreed, *to what*, *on which
channel*, *when*, *how* and *with which wording*. That is an append-only ledger, not a
flag.

## Design decisions (lock at kickoff)

| # | Decision | Rationale |
|---|---|---|
| M1 | **Every marketing send targets a subscription list.** "Offers & promotions" is a seeded system list, "Newsletter" another. Consent = granted membership of (contact, channel, list). | One consent rule for campaigns and newsletters; the preference center is a list × channel grid. |
| M2 | Consent is an **append-only ledger** (`consent_events`); current state = latest event per (contact, channel, list), computed, never stored. | Invariant 5 (derived state) + legal proof. |
| M3 | Marketing **never reads `contact_channels.opted_in`**. | It is set by triage/AI without consent evidence. |
| M4 | Automations, AI tools and imports without a source **may withdraw but never grant** consent. | Granting needs evidence. |
| M5 | `channel_suppressions` stays the address-keyed hard gate; an unsubscribe writes **both** a `withdrawn` consent event and (for "unsubscribe from all") the marketing suppression. | Suppression protects addresses not yet linked to a contact. |
| M6 | Unsubscribe `GET` becomes **non-destructive** (renders the preference center); one-click `POST` (RFC 8058) unsubscribes. | Today `GET /comms/unsubscribe/{token}` suppresses on GET, so corporate link scanners unsubscribe people. Mass mailing would amplify this. |
| M7 | Marketing mail goes through a **separate provider account/stream** (`communication_accounts.purpose`). | Postmark forbids broadcast on transactional streams; protects dunning/offer deliverability. |
| M8 | Marketing footer (legal entity identity + unsubscribe/preferences links) is **appended by the renderer**, not editable in templates. | LSSI art. 20 sender identification can't be forgotten. |

### ⚠ Legal questions for the client's lawyer / gestor (answer before S31 goes live)

1. **Soft opt-in (LSSI art. 21.2)** — may existing tenants receive marketing for
   similar services without explicit opt-in? If yes, S31 records it as a
   `granted` event with `source = soft_opt_in` at contract signing (still evidence,
   still withdrawable). Default: **off**.
2. Consent status of contacts imported from SpaceManager at cutover.
3. Retention of consent evidence after a GDPR erasure request (we propose: keep the
   event skeleton, null the evidence payload — see 01).

## Exit criteria

- [ ] An operator records a consent for a contact on a channel/list and the contact
      timeline shows who, when, how and which consent text.
- [ ] A visitor signs up through an embedded form, receives a confirmation email,
      confirms, and appears as a granted newsletter member; unconfirmed signups never do.
- [ ] A recipient opens the preference center from a link, leaves one list, keeps
      another; a link scanner's GET changes nothing.
- [ ] An operator saves a segment ("lost leads, last 90 days, Madrid Centro") and sees
      a live count restricted to sites they can see.
- [ ] A marketing-class email resolves the marketing provider account and carries the
      legal footer.

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Sprint 29 review fixes](./00-sprint-29-review-fixes.md) | 0.5 day |
| 01 | [Consent ledger](./01-consent-ledger.md) | 1.5 days |
| 02 | [Subscription lists](./02-subscription-lists.md) | 0.5 day |
| 03 | [Unsubscribe & preference center](./03-unsubscribe-and-preference-center.md) | 1.5 days |
| 04 | [Signup forms & double opt-in](./04-signup-forms.md) | 1.5 days |
| 05 | [Segments & marketing filter fields](./05-segments.md) | 1.5 days |
| 06 | [Marketing send lane](./06-marketing-send-lane.md) | 1 day |
| 07 | [Invariants, docs & tests](./07-invariants-and-docs.md) | 0.5 day |

**Total ≈ 8.5 days.** 01 → 02 → (03, 04) ; 05 and 06 are independent and can run in
parallel with 01–04.

## Out of scope

Campaigns, newsletters and announcements (S31). Link tracking, attribution, promo
discounts, WhatsApp marketing, A/B tests (S32).
