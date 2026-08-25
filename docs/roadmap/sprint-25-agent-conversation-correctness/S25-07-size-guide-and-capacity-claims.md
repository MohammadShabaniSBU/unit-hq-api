# S25-07 — Size guide catalogue and capacity claim licensing

**Depends on:** S25-00
**Touches:** `unit-hq-api`, `unit-hq-panel`
**Trace evidence:** trace-30 (verdict `pass`)

## Problem

The single most useful line the agent produced was:

> *"For 20–24 standard boxes, a unit around 5–8 m² should work well."*

It is also entirely ungrounded. No tool supplied it. `forbidden_claim` returned
`pass`, because the enum has one case (`AvailabilityGuarantee`) and this is not
that.

A capacity claim is a commercial promise. A customer who rents a 5 m² on that
advice and cannot fit their goods has been mis-sold, and the transcript shows
the operator's own assistant making the recommendation. The size-to-contents
mapping is also genuinely operator-specific: it depends on ceiling height,
whether the class is drive-up, and what the operator counts as a "standard box".

This is the concrete case for why `ForbiddenClaimKey` needed to grow beyond one
member — and S24 was right to ship it with exactly one until a real second
arrived. This is the real second.

## What to build

### Schema

```sql
create table size_guides (
    id             bigserial primary key,
    site_id        bigint null references sites,        -- null = company default
    unit_class_id  bigint null references unit_classes, -- null = size-band rule
    metric         varchar not null,   -- standard_boxes | room_equivalent | vehicle
    min_size       numeric(8,2) null,  -- m², when unit_class_id is null
    max_size       numeric(8,2) null,
    min_quantity   integer null,
    max_quantity   integer null,
    notes          text null,
    archived_at    timestamp null,
    created_at, updated_at
);
-- index (metric, archived_at)
```

Archive-only. Resolution prefers a site-specific row over a company default, and
a class-specific row over a size band — the same site-over-org preference shape
as `ProviderResolver`.

Seed a conservative default table. Conservative on purpose: over-recommending
size costs the customer money and produces a complaint; under-recommending
produces a mis-sale and a move-out. Err large, and say so in `notes`.

### Tool

`facility.size_guide(metric, quantity?, site_id?)` → matching bands, each
emitted as an `EntityRef` so it licenses the claim and (via S25-01) any
subsequent class-specific call. Result carries `notes` and a disclaimer string.

### Claim licensing

Add `ForbiddenClaimKey::CapacityGuidance`. A claim that a given quantity of
goods fits a given size or class is licensed **only** by a `facility.size_guide`
result in the same turn. Turn-scoped and never persisted, consistent with S24's
existing licence model.

Unlicensed capacity claims are suppressed, not rewritten — the agent asks what
the customer is storing and calls the tool.

The recommendation, when licensed, carries the guide's disclaimer. "Should work
well" is fine; "will fit" is not, and the disclaimer is what keeps the
difference visible.

### Panel

Settings → Facility → Size guides. List, create, edit, archive. i18n under
`facility.size_guides.*`.

## Acceptance criteria

- [ ] A capacity claim with no `facility.size_guide` call in the turn is
      suppressed; eval fixture reproduces the trace-30 turn and shows
      suppression.
- [ ] With the tool called, the claim passes and the response cites the band and
      carries the disclaimer.
- [ ] Site-specific rows beat company defaults; class-specific rows beat size
      bands.
- [ ] Archived rows never resolve.
- [ ] `ForbiddenClaimKey` now has two cases, both documented in
      `14-ai-agents.md` with their licensing sources.

## Out of scope

**The retrieval / knowledge-base layer.** This is one bounded catalogue table
answering one recurring question, not a RAG surface. Vector storage, document
ingestion, and chunk-level retrieval licensing are a separate sprint with a
separate design — do not start them here on the grounds that size guides are
"knowledge".
