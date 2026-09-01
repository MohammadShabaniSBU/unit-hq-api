# agents:recall

Kill-switch second half. `AGENTS_ENABLED=false` stops new turns; this undoes agent-created offers and reservations already written.

Not a route. Not a panel button. Operator runbook only.

```bash
php artisan agents:recall --agent=concierge --since=1h
php artisan agents:recall --agent=concierge --since=1h --dry-run=false
php artisan agents:recall --agent=concierge --since=30m --offers
php artisan agents:recall --agent=concierge --since=2d --reservations
```

## Flags

| Flag | Default | Notes |
|---|---|---|
| `--agent` | (required) | `ai_agents.key` (`concierge`, or an archived `sales` / `support` key for historical rows) |
| `--since` | `1h` | Duration: `30m`, `1h`, `2d` (`s` / `m` / `h` / `d`) |
| `--dry-run` | `true` | Preview only. Commit requires `--dry-run=false` |
| `--offers` | off | If neither `--offers` nor `--reservations` is passed, both run |
| `--reservations` | off | Same |

Dry-run prints a per-row plan and writes nothing. It emits Tier-1 `agents.recall.started` with `dry_run: true`. It does **not** emit `agents.recall.committed`.

## What it does on commit

- **Offers** with `source = ai_agent` for that agent in the window: `status = expired`, `expires_at = now()`. Never deleted — a token may already be in a prospect inbox; the public route must resolve to an expired offer, not a 404.
- **Reservations** with `source = ai_agent` for that agent in the window: `status = cancelled`, then `ReservationHolds::release()` (same path convert uses). No second release writer.

Each mutated row gets a core activity event (`offer.expired` / `reservation.cancelled`) with `reason: agents.recall`. Commit also emits `agents.recall.committed` with counts.

## What it refuses

- Any row whose `source` is not `ai_agent`. Operator / public-link / automation rows are not in the query.
- **Accepted offers** — the reservation may have become a contract. Printed as `SKIP offer #{id}  accepted` with ids in the SystemEvent payload. Not reversed.
- **Reservations that already have a contract** — printed as `SKIP reservation #{id}  has contract #{id}`. Not cancelled.

## When to use it

A looping concierge agent wrote bad offers or held units. Flip `AGENTS_ENABLED=false` first so new turns stop, then dry-run this command, then `--dry-run=false` once the plan looks right.
