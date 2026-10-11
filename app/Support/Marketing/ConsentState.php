<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Models\Contact;
use App\Models\SubscriptionList;
use App\Support\Communications\Channel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Current consent is the latest event per (contact, channel, list). Nothing is stored.
 */
final class ConsentState
{
    /**
     * Latest event per list and channel for one contact.
     *
     * @return Collection<int, array{list: array{id: int, key: string|null}, channel: string, action: string, occurred_at: string, source: string}>
     */
    public static function for(Contact $contact): Collection
    {
        $rows = DB::getDriverName() === 'pgsql'
            ? self::latestRowsPostgres((int) $contact->id)
            : self::latestRowsSqlite((int) $contact->id);

        $listIds = array_map(static fn (object $row): int => (int) $row->subscription_list_id, $rows);
        $keys = SubscriptionList::query()
            ->whereIn('id', $listIds)
            ->pluck('key', 'id');

        return collect($rows)->map(function (object $row) use ($keys): array {
            $listId = (int) $row->subscription_list_id;

            return [
                'list' => [
                    'id' => $listId,
                    'key' => $keys->get($listId),
                ],
                'channel' => (string) $row->channel,
                'action' => (string) $row->action,
                'occurred_at' => Carbon::parse($row->occurred_at)->toIso8601String(),
                'source' => (string) $row->source,
            ];
        })->values();
    }

    public static function isGranted(int $contactId, Channel|ConsentChannel $channel, int $listId): bool
    {
        $latest = DB::table('consent_events')
            ->where('contact_id', $contactId)
            ->where('channel', $channel->value)
            ->where('subscription_list_id', $listId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('action');

        return $latest === ConsentAction::Granted->value;
    }

    /**
     * Contact ids whose latest event on this list and channel is granted.
     * Postgres uses DISTINCT ON so the filter can ride ce_list_idx.
     */
    public static function grantedQuery(Channel|ConsentChannel $channel, int $listId): Builder
    {
        if (DB::getDriverName() !== 'pgsql') {
            return DB::table('consent_events as e')
                ->select('e.contact_id')
                ->where('e.subscription_list_id', $listId)
                ->where('e.channel', $channel->value)
                ->where('e.action', ConsentAction::Granted->value)
                ->whereRaw('e.id = (
                    SELECT e2.id FROM consent_events e2
                    WHERE e2.contact_id = e.contact_id
                      AND e2.subscription_list_id = e.subscription_list_id
                      AND e2.channel = e.channel
                    ORDER BY e2.occurred_at DESC, e2.id DESC
                    LIMIT 1
                )');
        }

        // MATERIALIZED keeps the list+channel filter on ce_list_idx. A single
        // DISTINCT ON (contact_id) query orders by contact_id and the planner
        // picks ce_state_idx instead.
        $sql = <<<'SQL'
            WITH scanned AS MATERIALIZED (
                SELECT contact_id, action, occurred_at, id
                FROM consent_events
                WHERE subscription_list_id = ? AND channel = ?
            )
            SELECT contact_id FROM (
                SELECT DISTINCT ON (contact_id) contact_id, action
                FROM scanned
                ORDER BY contact_id, occurred_at DESC, id DESC
            ) latest
            WHERE action = ?
            SQL;

        return DB::query()->fromRaw(
            '('.$sql.') as granted_contacts',
            [$listId, $channel->value, ConsentAction::Granted->value],
        )->select('contact_id');
    }

    /**
     * @return list<object>
     */
    private static function latestRowsPostgres(int $contactId): array
    {
        return DB::select(
            <<<'SQL'
            SELECT DISTINCT ON (subscription_list_id, channel)
                subscription_list_id, channel, action, occurred_at, source
            FROM consent_events
            WHERE contact_id = ?
            ORDER BY subscription_list_id, channel, occurred_at DESC, id DESC
            SQL,
            [$contactId],
        );
    }

    /**
     * @return list<object>
     */
    private static function latestRowsSqlite(int $contactId): array
    {
        return DB::select(
            <<<'SQL'
            SELECT e.subscription_list_id, e.channel, e.action, e.occurred_at, e.source
            FROM consent_events e
            WHERE e.contact_id = ?
              AND e.id = (
                SELECT e2.id FROM consent_events e2
                WHERE e2.contact_id = e.contact_id
                  AND e2.subscription_list_id = e.subscription_list_id
                  AND e2.channel = e.channel
                ORDER BY e2.occurred_at DESC, e2.id DESC
                LIMIT 1
              )
            SQL,
            [$contactId],
        );
    }
}
