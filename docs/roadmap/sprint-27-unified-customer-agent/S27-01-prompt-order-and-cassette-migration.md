# S27-01 — Prompt block order, fixture migration, one re-record

**Depends on:** S27-00, S27-04
**Blocks:** S27-05 (sprint DoD)
**Touches:** `unit-hq-api` (tests/fixtures)

## Problem

Two unrelated changes both invalidate every cassette, and doing them in
separate passes pays the recording cost twice.

**One — the tool surface changed.** `CassetteKey::schemaHash()` canonicalises
`{key, description, schema}` for every tool in the agent's list. S27-00's
union surface changes that hash for both former agents, so all cassettes are
stale before a single prompt byte moves.

**Two — the prompt prefix is unstable by construction.**
`AssemblesSystemPrompt::systemPrompt()` emits:

```
roleParagraph, identityBlock, disclosureBlock, untrustedInputBlock,
channelBlock, verificationBlock, toolContractBlock, neverListBlock,
escalationBlock
```

`identityBlock()` is part 2 and contains `Site: {name}`, `Civil timezone:`,
and `Today: {date}`. Everything after it is stable, but a provider caches on
prefix, so the cache breaks every midnight and again for every site. The
stable ~85% of the prompt sits behind the volatile 15%.

## What to build

### Reorder

Move `identityBlock` and `disclosureBlock` to the **end** of the `$parts`
array in `systemPrompt()`:

```
roleParagraph, untrustedInputBlock, channelBlock, verificationBlock,
toolContractBlock, neverListBlock, escalationBlock,
identityBlock, disclosureBlock
```

`promptVersion()` needs no change — it already excludes both blocks, with a
comment explaining why (first-turn disclosure is conversation state). The
comment stays accurate.

Read the semantics before moving: `disclosureBlock` is an *instruction* to
open with the disclosure sentence, not the sentence itself, so its position
in the prompt does not change what the model says first. Confirm against the
`sales/disclosure-opens-first-turn.yaml` fixture rather than by reading.

### Measure the prize before and after

Before the re-record, run:

```sql
SELECT provider, sum(cached_input_tokens), sum(input_tokens)
FROM ai_usage_events
WHERE created_at > now() - interval '30 days'
GROUP BY provider;
```

If `cached_input_tokens` is already non-zero the reorder is worth its cost;
if it is zero on every provider, find out whether that is this ordering or
`laravel/ai` issue #119 (a `SystemMessage` built from a string never carries
`cache_control` to Anthropic) before assuming the reorder fixes it. Record
the numbers in the task's PR description either way — this is the only
measurement that tells us whether prompt caching works at all.

### Fixture migration

`tests/Fixtures/agents/{sales,support}/` — 57 fixtures, each with a
`cassettes/` sibling keyed by prompt and schema hash.

1. `git mv tests/Fixtures/agents/sales tests/Fixtures/agents/concierge`, then
   move `support/*.yaml` into the same directory. Filename collisions:
   `grounding-invented-unit-id.yaml` and `grounding-invented-vat.yaml` exist
   in both. Suffix the support ones `-tenant` rather than merging them; they
   assert different tool paths.
2. Rewrite each fixture's `agent:` key to `concierge` and its `id:` prefix to
   `concierge/`.
3. Delete both `cassettes/` directories. Do not attempt to rekey them; both
   hashes changed.
4. Re-record in one pass with `RecordingModelDriver` against the real
   provider.

Fixtures whose `principal.verification` is `verified` (the former support
suite) now exercise the verified branch of the role paragraph; fixtures at
`anonymous` / `channel_asserted` exercise the other. No fixture's assertions
should need editing — if one does, that is a behaviour change S27-00 did not
intend and is a finding, not a fixture fix.

### New fixtures

Three, covering what the split made untestable:

| Fixture | Asserts |
|---|---|
| `concierge/tenant-asks-price-second-unit.yaml` | verified principal, `pricing.quote` + `sales.propose_offer` reachable, no handoff. Previously impossible: support had no pricing tools |
| `concierge/prospect-asks-balance.yaml` | `channel_asserted` principal asking for a balance → `denied: verification` on `billing.balance`, agent offers verification, no leak. Previously covered by tool absence; now covered by the gate |
| `concierge/prospect-becomes-tenant-midthread.yaml` | one conversation crossing the old boundary: quote → offer → verification → balance |

The third is the sprint's DoD in fixture form.

## Acceptance criteria

- [ ] `systemPrompt()` emits identity and disclosure last; `promptVersion()`
      output is unchanged for an identical role paragraph.
- [ ] `sales/disclosure-opens-first-turn.yaml` (migrated) still passes:
      disclosure still opens turn one.
- [ ] All 57 migrated fixtures pass with freshly recorded cassettes; zero
      `EvalCassetteStaleException`.
- [ ] Three new fixtures pass.
- [ ] `tests/Fixtures/agents/{sales,support}` no longer exist;
      `_harness/` fixtures updated to whatever key they assert against.
- [ ] Cached-token query results recorded in the PR, before and after.

## Out of scope

- `laravel/ai` issue #119 itself. If explicit `cache_control` cannot reach
  the provider through the SDK, record that in `10-open-decisions.md` under
  Undecided with the measured numbers; do not fork the SDK in this sprint.
- Any change to `CassetteKey`. The hashes are doing exactly their job here.
