<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AgentChannelBinding;
use App\Support\Ai\Enums\AgentChannel;

/**
 * Resolves which agent answers a channel at a site:
 * 1. live site-scoped row (when a site id is given)
 * 2. else live company-scoped row (`site_id` null)
 * 3. else null (off — absent row is deliberately the opposite of write policies)
 *
 * Rows whose agent is inactive or archived are skipped. The listener never
 * reads `agent_channel_bindings` directly.
 */
final class AgentChannelBindings
{
    public function resolve(AgentChannel $channel, ?int $siteId = null): ?ResolvedBinding
    {
        if ($siteId !== null) {
            $siteRow = $this->liveRow($channel, $siteId);
            if ($siteRow !== null) {
                return $this->toResolved($siteRow);
            }
        }

        $companyRow = $this->liveRow($channel, null);

        return $companyRow !== null ? $this->toResolved($companyRow) : null;
    }

    private function liveRow(AgentChannel $channel, ?int $siteId): ?AgentChannelBinding
    {
        $query = AgentChannelBinding::query()
            ->live()
            ->where('channel', $channel)
            ->with('agent');

        if ($siteId === null) {
            $query->whereNull('site_id');
        } else {
            $query->where('site_id', $siteId);
        }

        $row = $query->first();
        if ($row === null) {
            return null;
        }

        $agent = $row->agent;
        if (! $agent->is_active || $agent->archived_at !== null) {
            return null;
        }

        return $row;
    }

    private function toResolved(AgentChannelBinding $row): ResolvedBinding
    {
        return new ResolvedBinding(
            agent: $row->agent,
            mode: $row->mode,
            audience: $row->audience,
            outsideHours: $row->outside_hours,
            binding: $row,
        );
    }
}
