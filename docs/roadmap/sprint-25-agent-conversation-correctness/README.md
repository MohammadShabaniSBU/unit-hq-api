# Sprint 25 — Agent Conversation Correctness

**Origin:** review of a demo SMS conversation on the Keevaris sales agent
(Madrid Centro, site 1, 2026-08-25) plus the exported trace. The conversation
reached a qualified lead — startup, Madrid, 20–24 boxes — and ended on
*"shall I clarify which matches which size?"*. No contact, no deal, no offer.

S24 shipped the governance layer (write policy, dispatch gate, propose/commit,
claim licensing, provenance). This sprint fixes the layer above it: the tools
themselves, what the model is allowed to feed them, and what the operator can
see afterwards.

## Findings → tasks

| # | Finding | Trace evidence | Task |
|---|---|---|---|
| 1 | Agent quoted a price for `unit_class_id: 2`, never returned by any tool. All five guards passed. | trace-27 returned classes 1,4,6,7,8,10,11; trace-35 quoted 2 | S25-01 |
| 2 | Tool results carry no human-readable identity, forcing the model to keep an id→label map across turns | trace-34/35 | S25-00 |
| 3 | Tool errors are prose; agent escalated to human handoff instead of calling another tool | trace-13 | S25-00 |
| 4 | No way to resolve a postcode/city to a site; `site_id` was hard-required | trace-13 | S25-02 |
| 5 | `pricing.quote` has no period, no `price_id`, no label — "€346.80" is not a quote | trace-34/35 | S25-03 |
| 6 | Channel guard counted 1→2→4→5→7 segments and passed every time; gsm7→ucs2 flip on "m²"/"€" | trace-5 … trace-40 | S25-04 |
| 7 | `estimated_cost: null, currency: null` on every usage row | trace-6, 12, 19, 26, 33, 41 | S25-05 |
| 8 | Trace rows carry no conversation/turn/message/model/prompt-version; association is by array order only | whole export | S25-05 |
| 9 | Context grew 2,653 → 7,549 input tokens over six trivial turns | usage rows | S25-05 |
| 10 | Panel paints "7 segments · ucs2" on every bubble including inbound | screenshots | S25-06 |
| 11 | Ungrounded capacity claim ("20–24 boxes → 5–8 m²") passed `forbidden_claim` | trace-30 | S25-07 |
| 12 | Demo catalogue prices implausible; 8 m² cheaper than 5 m² | trace-34/35 | S25-03 |

## Sequencing

```
S25-00 ──┬── S25-01
         ├── S25-02
         ├── S25-03
         └── S25-07
S25-04 (independent)
S25-05 ──── S25-06
S25-08 (last — invariants + docs)
```

S25-00 changes every tool's return shape and is the prerequisite for the
provenance guard, so it lands first. S25-04 and S25-05 touch nothing S25-00
touches and can run in parallel.

## Open question for QA before S25-01 starts

The agent never reached for `sales.create_offer` or `sales.create_reservation`.
Determine which is true, because it changes whether this sprint is complete:

1. Those tools are not in the demo-origin agent's `agent_write_policies` set —
   expected, and the conversation simply never got far enough to matter.
2. They are in the set and the model did not select them — a prompt/tool-
   description problem that needs its own task.

Check the policy rows for the demo agent, then replay the conversation with the
S25-01 and S25-02 fixes in place and see whether it converts.

## Convention notes for implementers

- No `app/Services/`. Everything here lands under `App\Support\Agents\`,
  `App\Support\Facility\`, or `App\Support\Communications\`.
- Migrations must run on SQLite (local/test) and PostgreSQL (deploy). No
  PostGIS, no SQL geo functions — distance math is PHP.
- Civil dates through `SiteClock` (invariant 32). `as_of` is site-local.
- Money is `NUMERIC(10,2)`; currency comes from `prices.currency` (invariant 29).
- Panel: all strings through i18n (en/es/fr), `Array<T>`, `useApi()`.
- New tables that operators edit are archive-only (`archived_at`), never
  hard-deleted.
