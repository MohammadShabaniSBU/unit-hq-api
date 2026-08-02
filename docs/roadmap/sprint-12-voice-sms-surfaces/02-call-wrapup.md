# S12-02 — Call wrap-up: disposition, notes, recordings

## Context

A call that leaves no trace teaches the CRM nothing. Wrap-up turns each call message
into data: what kind of conversation was it (disposition), what was said/agreed
(note), and the recording/voicemail playable where the conversation lives.

## Scope

**In:** disposition + note on call messages, wrap-up prompt after correlated outbound
calls, recording/voicemail playback via fresh-URL proxy, disposition vocabulary as
config. **Out:** transcription (deferred, noted), QA/scoring workflows, mandatory
wrap-up enforcement (prompted, never blocking — solo-operator reality).

## Behaviour

**Data.** No migration needed if honest: disposition + note ride the call message's
`source_ref`/detail JSON? **No** — wrap-up is operator CRM data, queryable later
(S17 call reports): add columns to `messages`… also no (99% of messages aren't calls).
Resolution: `call_wrapups` table — `message_id` unique FK, `disposition VARCHAR`,
`note TEXT`, `employee_id`, timestamps. One honest table, reportable, and the
zero-migration streak was an S11 rule, not a forever rule.

Disposition vocabulary from config seeded sensibly:
`reached | voicemail_left | no_answer | wrong_number | payment_promised |
callback_requested | resolved | other` — `payment_promised` is the one S17's
collections reporting will want; keep the values machine-keyed, panel-translated
(the invariant-14 idiom applied to config).

**Wrap-up prompt.** When the operator's own correlated call ends (banner clears with
`ended` + intent match to *this* employee), the banner morphs into a wrap-up strip:
disposition chips + optional note + Save/Dismiss. Dismiss leaves the call
disposition-less (visible as such in the thread); the strip never blocks navigation.
Wrap-up also editable later from the call card's menu (append-style: edits update the
one row, `updated_at` honest — this is operator annotation, not ledger).

**Playback.** Call cards and voicemail rows gain a player. Source: `GET
/api/messages/{id}/recording` → server fetches a *fresh* recording URL from Aircall
(the README expiring-URL rule), streams or 302s per what their API allows — decide at
implementation against current docs, never persist signed URLs. Missing/expired
recording renders "Recording unavailable" honestly. A note in the file: recordings are
personal data — they inherit the contact's GDPR redaction scope
(`contacts:redact` gains: delete wrapup notes, mark recordings unavailable; add to
`config/redaction.php`).

**Timeline value.** Wrap-up disposition surfaces on: the call card (chip), the
delinquency case timeline entry (a `payment_promised` chip on the case is collections
gold), and the contact's recent-activity summary (S11 context panel `recent` gains
disposition text for calls).

## Acceptance criteria

- [ ] Wrap-up strip appears only for the caller's own ended correlated calls; save/
      dismiss/late-edit all work; disposition renders across the three surfaces.
- [ ] Playback streams via fresh-fetch; expired URL degrades honestly; no signed URL
      persisted anywhere (grep).
- [ ] Voicemail playback marks the thread read only on explicit open (S11 semantics
      held).
- [ ] Redaction covers wrap-up notes + recording availability.
- [ ] Disposition values machine-keyed + translated; config-extendable without
      migration.

## Tests required

| Test | Asserts |
|---|---|
| `WrapupTest::own_call_prompt_and_edit` | Ownership + lifecycle |
| `WrapupTest::disposition_surfaces_thrice` | Card, case, context |
| `RecordingTest::fresh_fetch_never_persisted` | Grep + proxy behaviour |
| `RedactionTest::wrapups_and_recordings` | GDPR extension |
