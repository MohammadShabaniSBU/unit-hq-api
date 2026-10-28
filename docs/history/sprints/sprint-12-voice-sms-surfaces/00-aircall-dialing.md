# S12-00 — Aircall users & dialing

## Context

The mapping and the dial primitive. Aircall's model: calls are placed *by users on
devices*; our job is knowing which employee is which Aircall user, and asking Aircall
to make that user's device dial a number. Everything visible in 01 sits on these two
pieces.

## Scope

**In:** Aircall user sync + employee mapping (settings), dial endpoint with intent
recording, click↔call correlation, availability read. **Out:** number purchasing/
management (Aircall dashboard's job), multi-account Aircall (one account per install
v1 — the existing `communication_accounts` company scope), presence/live status beyond
the availability check.

## Schema changes

```sql
CREATE TABLE aircall_user_links (
    id BIGSERIAL PRIMARY KEY,
    employee_id BIGINT NOT NULL REFERENCES employees(id),
    aircall_user_id VARCHAR(32) NOT NULL,
    aircall_user_label VARCHAR(128) NOT NULL,   -- name/number for display
    created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE UNIQUE INDEX aul_employee_idx ON aircall_user_links (employee_id);
CREATE UNIQUE INDEX aul_user_idx ON aircall_user_links (aircall_user_id);

CREATE TABLE call_intents (
    id BIGSERIAL PRIMARY KEY,
    employee_id BIGINT NOT NULL REFERENCES employees(id),
    contact_id BIGINT NOT NULL REFERENCES contacts(id),
    to_number VARCHAR(32) NOT NULL,             -- E.164
    context_type VARCHAR(24) NULL,              -- thread | delinquency | task | contact
    context_id BIGINT NULL,
    aircall_call_id VARCHAR(32) NULL,           -- from dial response when provided
    message_id BIGINT NULL REFERENCES messages(id),  -- set at webhook correlation
    status VARCHAR(16) NOT NULL DEFAULT 'requested', -- requested | dial_failed | correlated | uncorrelated
    error TEXT NULL,
    created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE INDEX ci_correlation_idx ON call_intents (aircall_call_id) WHERE aircall_call_id IS NOT NULL;
```

## Behaviour

- **User sync.** Settings → Communications → Aircall account gains a Users section:
  fetch `GET /v1/users` through the stored credentials, list, and let an admin map
  employees (select per row). Sync on demand (button), cached list; unlink allowed.
  Credential discipline as ever; the fetch failing surfaces the account status, not
  a 500.
- **Dial.** `POST /api/calls/dial { contact_id, to_number?, context: {type,id}? }` —
  number defaults to the contact's primary phone; requires the *caller's* mapping
  (422 `not_mapped` with the settings pointer otherwise). Insert the intent, call
  Aircall's dial endpoint for the mapped user; API rejection → `dial_failed` + error
  surfaced (agent offline / device unavailable are normal — the message says what to
  do, not just that it failed). Success stores `aircall_call_id` when the response
  carries one.
- **Correlation.** The S10 Aircall webhook consumer gains one lookup: on
  `call.created`(outbound), match `aircall_call_id` → intent (else fallback: same
  user + same number within 2 minutes, flagged `heuristic` in evidence) → stamp
  `message_id` on the intent and `source_ref.call_intent` on the message with the
  click context — this is what lets 01 land the call on the *delinquency case
  timeline* the click came from. Unmatched intents age to `uncorrelated` after 10
  minutes (sweep), visible in the account's health counters.
- **Availability.** `GET /api/calls/availability` → caller's mapping + Aircall user
  availability (their API exposes it) → the enable/disable truth for every call
  button, cached 60s. Buttons never guess.

## Acceptance criteria

- [ ] Sync lists users; mapping unique both directions; unlink works.
- [ ] Dial happy path (fake client) records intent → webhook fixture correlates →
      message carries context; heuristic path flagged; uncorrelated ages out.
- [ ] Unmapped caller and Aircall rejection both fail actionably (message keys).
- [ ] Availability endpoint drives a disabled state end-to-end.
- [ ] No call message is ever created by the dial path itself (grep + test — the
      no-optimism rule).

## Tests required

| Test | Asserts |
|---|---|
| `AircallMappingTest::sync_map_unlink_unique` | Both unique indexes |
| `DialTest::intent_correlation_exact_and_heuristic` | The join |
| `DialTest::failures_actionable_no_message_synthesis` | Posture |
| `AvailabilityTest::drives_button_truth` | Cached contract |
