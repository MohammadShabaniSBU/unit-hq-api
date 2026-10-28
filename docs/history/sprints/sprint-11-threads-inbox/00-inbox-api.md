# S11-00 — Inbox API surface

## Context

Read endpoints shaped for the three panes, plus the small writes (read, assign). The
contract here *is* the page's performance budget and its future realtime upgrade path —
design payloads once, transport-agnostic.

## Scope

**In:** thread list aggregate, thread detail + message pagination, delta polling
contract, mark-read, assign, badge counts. **Out:** sending (01), triage endpoints
(exist from S10 / completed in 04), search beyond v1 fields.

## Endpoints

```
GET /api/inbox/threads
    ?channel=email|sms|call & filter=mine|unassigned|all & unread=1
    & q=<name/address/subject> & cursor=<opaque> & updated_after=<iso>
→ { data: [ThreadSummary], meta: { next_cursor } }

ThreadSummary: { id, channel, contact: {id, name, avatar_initials},
  subject|channel_key, preview: {direction, body_excerpt, status, at},
  unread_count, assigned_employee: {id, name}|null, last_message_at,
  suppressed: bool }   // composer pre-warning, computed via SuppressionWriter

GET /api/inbox/threads/{id}?before=<message cursor>
→ thread + messages page (newest-first pages, client renders ascending),
  each message: direction, status, body (sanitized html|text), attachments
  [{id, filename, size, mime}], source badge (manual|offer|playbook|system),
  delivery timestamps, from/to

GET  /api/inbox/badge           → { unread_threads, triage_count }
POST /api/inbox/threads/{id}/read     → unread_count = 0 (idempotent)
POST /api/inbox/threads/{id}/assign   { employee_id|null }   // null = unassign
GET  /api/message-attachments/{id}/download   → private-disk stream, auth'd
```

## Implementation notes

- **One aggregate query** for the list: threads filtered + lateral/sub-select for the
  latest message preview + contact join. Assert query count in a test at 25-per-page ×
  500 threads. Cursor = encoded `(last_message_at, id)` tuple; `updated_after` returns
  changed threads only (same payload — the poller merges by id).
- `filter=mine` = `assigned_employee_id = auth`, `unassigned` = null; the `canEdit`-era
  stopgap guards assign (S17 caller list grows by one, noted).
- Excerpts: text-stripped, 120 chars, generated at read (no stored excerpt column —
  invariant 5 hygiene).
- Mark-read + assign write Tier-2 `comms` activity? **No** — high-noise; assignment
  yes (`thread.assigned`, comms channel), read no. Pin it.
- Attachment download authenticates (Sanctum) and streams; no public URLs ever
  (S10 invariant honoured at the route level).

## Acceptance criteria

- [ ] All filters compose; cursor pagination stable under a mid-scroll new arrival
      (fixture inserts between pages, no skip/double).
- [ ] `updated_after` returns exactly the changed threads; payload identical to list.
- [ ] Query-count + timing assertions on the 500-thread seed.
- [ ] Mark-read idempotent; benign-race semantics per README (test the interleaving).
- [ ] Assign audits; unassign works; stopgap guard applied.
- [ ] Attachment route streams auth'd, 403s cross-auth… (single-tenant: just auth'd).

## Tests required

| Test | Asserts |
|---|---|
| `InboxListTest::filters_compose_bounded_queries` | The aggregate |
| `InboxListTest::cursor_stable_under_arrivals` | Moving-inbox pagination |
| `InboxDeltaTest::updated_after_contract` | Poller's diet |
| `ReadStateTest::idempotent_and_benign_race` | README semantics |
| `AssignTest::audit_and_stopgap` | Accountability |
