# S11-03 — Context panel & quick actions

## Context

The right pane — the reason this inbox beats a shared Gmail. The operator answering
"do you have anything bigger?" sees the tenant's unit, balance, open deal, and can act
without leaving: the mockup's Send offer / Request payment, live. Every element here is
a **read over existing endpoints** plus deep-links into flows other sprints built.

## Scope

**In:** context aggregate endpoint, the panel UI, quick actions wiring, state-aware
chips (delinquency, enrolment, autopay), per-thread caching. **Out:** send-contract
action (S15 — rendered disabled with "coming with e-signature"), inline editing of
contact fields (link to the contact page instead; the panel is glanceable, not a form).

## Endpoint

```
GET /api/inbox/threads/{id}/context →
{ contact: { id, name, status, email, phone, fiscal_complete },
  tenancy: { active_contracts: [{ id, unit_number, site_name, monthly_display,
             balance: {owed, overdue, currency}, autopay: on|off|failing,
             delinquency: {days, stage_label} | null }] ,
  pipeline: { open_deal: { id, title, stage, move_in } | null,
              lead_enrolment: { playbook, step_x_of_y, next_at } | null },
  recent: [ last 3 cross-channel interactions (type, at, summary) ] }
```

One aggregate, computed live (balances via the standing computations — nothing new
stored), bounded queries asserted. Multi-contract tenants render each contract block;
the panel scrolls.

## Panel surface

Top: contact card (avatar, name, status badge, channel values with suppression
micro-icons, "View contact →"). **Tenancy block(s)** per active contract: unit +
site, balance with overdue in red, autopay chip (`failing` amber links the attempt),
delinquency chip when open ("Day 12 · Overlocked" linking the case), "View contract →".
**Pipeline block:** open deal (stage badge, move-in, "View deal →") and the lead-chase
chip ("Step 2 of 4 · next SMS in 1d" linking the enrolment) — the S09 cross-link
promise, now visible where it matters. **Quick actions** (sticky footer):

- **Request payment** → S06's drawer, contract preselected (multi-contract: chooser),
  on create drops the link into the composer as an insertable snippet — the payment
  link *in the reply* is the whole flow the first mockup sketched.
- **Send offer** → routes to New offer with contact (+ deal when open) prefilled;
  returns to the thread after (return-to query param).
- **Add task** → quick modal (title, due, urgent), relatedTo the contact.
- **Send contract** → disabled, S15 tooltip.

Empty states honest: a prospect with no tenancy shows pipeline only; a pure-triage
identity (shouldn't exist post-resolution) shows the contact card.

i18n `inbox.context.*`; es: Request payment reuses `billing.paymentRequests` keys.

## Acceptance criteria

- [ ] Seeded personas render correctly: active tenant in delinquency (all chips),
      multi-contract tenant, prospect with open deal + enrolment, clean tenant.
- [ ] Balance/overdue/autopay/delinquency figures equal their source pages (fixture
      equality — no panel-side arithmetic drift).
- [ ] Request-payment round trip: drawer → link created → snippet inserted → sent
      message contains the working link → (test-mode) payment marks the request paid.
- [ ] Send-offer prefill + return-to; task creation lands on the contact.
- [ ] Enrolment chip matches engine state (the S09 progress-dots data reused).
- [ ] Aggregate bounded-queries + cache-per-thread (invalidated by the poller's
      thread-updated signal).

## Tests required

| Test | Asserts |
|---|---|
| `ContextTest::aggregate_shapes_and_bounded` | The endpoint |
| `ContextTest::figures_equal_source_pages` | No drift |
| `QuickActionTest::payment_link_roundtrip` | The flagship flow |
| Panel manual script | Personas sweep, prefills, return-to |
