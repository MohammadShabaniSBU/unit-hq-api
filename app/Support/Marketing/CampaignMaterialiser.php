<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSendStatus;
use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use App\Support\Communications\Channel;
use App\Support\Filtering\ContactMarketingFields;
use Illuminate\Support\Facades\DB;

/**
 * Inserts one recipient row per candidate contact. Re-running is a no-op on
 * the unique (campaign_send_id, contact_id) key.
 */
final class CampaignMaterialiser
{
    public const CHUNK = 500;

    public function __construct(
        private readonly CampaignAudienceQuery $audience,
        private readonly CampaignBatchPlanner $batches,
        private readonly CampaignSendCompletion $completion,
    ) {}

    public function materialise(CampaignSend $send): void
    {
        $send->refresh();
        if (! in_array($send->status, [CampaignSendStatus::Materialising, CampaignSendStatus::Sending], true)) {
            return;
        }

        $alreadySending = $send->status === CampaignSendStatus::Sending;
        $send->loadMissing('campaign');
        $campaign = $send->campaign;
        if ($campaign === null) {
            return;
        }

        $ids = $this->audience->candidates($campaign, $send->channel)
            ->reorder()
            ->select('contacts.id')
            ->distinct()
            ->orderBy('contacts.id')
            ->pluck('id');

        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            $this->insertChunk($send, array_map(intval(...), $chunk->all()));
        }

        if ($alreadySending) {
            return;
        }

        $send->refresh();
        $campaign = Campaign::query()->find($send->campaign_id);
        if ($campaign === null || $send->status === CampaignSendStatus::Cancelled || $campaign->status === CampaignStatus::Cancelled) {
            $this->skipPending($send, CampaignSkipReason::Cancelled);

            return;
        }

        $send->status = CampaignSendStatus::Sending;
        $send->materialised_at = now();
        $send->save();

        if ($campaign->status === CampaignStatus::Sending) {
            $this->batches->queuePending($send);
        }

        $this->completion->completeSendIfIdle($send->fresh() ?? $send);
    }

    /**
     * @param  list<int>  $contactIds
     */
    private function insertChunk(CampaignSend $send, array $contactIds): void
    {
        if ($contactIds === []) {
            return;
        }

        $addressSql = $this->addressSql($send->channel);
        $siteSql = ContactMarketingFields::businessSiteExpression();
        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $now = now()->format('Y-m-d H:i:s');
        $idType = DB::getDriverName() === 'pgsql' ? 'bigint' : 'integer';
        $timeType = DB::getDriverName() === 'pgsql' ? 'timestamptz' : 'text';

        $sql = <<<SQL
            INSERT INTO campaign_recipients (
                campaign_send_id, contact_id, address, site_id, status, skip_reason, processed_at, attempts, created_at, updated_at
            )
            SELECT
                resolved.campaign_send_id,
                resolved.contact_id,
                resolved.address,
                resolved.site_id,
                CASE WHEN resolved.address IS NULL THEN ? ELSE ? END,
                CASE WHEN resolved.address IS NULL THEN ? ELSE NULL END,
                CASE WHEN resolved.address IS NULL THEN CAST(? AS {$timeType}) ELSE NULL END,
                0,
                CAST(? AS {$timeType}),
                CAST(? AS {$timeType})
            FROM (
                SELECT
                    CAST(? AS {$idType}) AS campaign_send_id,
                    contacts.id AS contact_id,
                    NULLIF(TRIM({$addressSql}), '') AS address,
                    ({$siteSql}) AS site_id
                FROM contacts
                WHERE contacts.id IN ({$placeholders})
            ) AS resolved
            ON CONFLICT (campaign_send_id, contact_id) DO NOTHING
            SQL;

        DB::statement($sql, [
            CampaignRecipientStatus::Skipped->value,
            CampaignRecipientStatus::Pending->value,
            CampaignSkipReason::NoAddress->value,
            $now,
            $now,
            $now,
            $send->id,
            ...$contactIds,
        ]);
    }

    private function addressSql(Channel $channel): string
    {
        $primary = DB::getDriverName() === 'pgsql'
            ? 'contact_channels.is_primary IS TRUE'
            : 'contact_channels.is_primary = 1';

        $primaryOf = static function (string $type) use ($primary): string {
            return '(SELECT contact_channels.value FROM contact_channels'
                .' WHERE contact_channels.contact_id = contacts.id'
                ." AND contact_channels.type = '{$type}'"
                ." AND {$primary}"
                ." AND TRIM(contact_channels.value) <> ''"
                .' LIMIT 1)';
        };

        if ($channel === Channel::Email) {
            return 'COALESCE('.$primaryOf(Channel::Email->value).", NULLIF(TRIM(contacts.email), ''))";
        }

        return 'COALESCE('.$primaryOf($channel->value).', '.$primaryOf('phone').')';
    }

    private function skipPending(CampaignSend $send, CampaignSkipReason $reason): void
    {
        CampaignRecipient::query()
            ->where('campaign_send_id', $send->id)
            ->where('status', CampaignRecipientStatus::Pending)
            ->update([
                'status' => CampaignRecipientStatus::Skipped->value,
                'skip_reason' => $reason->value,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
