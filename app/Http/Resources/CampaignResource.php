<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CampaignSend;
use Illuminate\Http\Request;

class CampaignResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type?->value ?? $this->type,
            'name' => $this->name,
            'status' => $this->status?->value ?? $this->status,
            'subscription_list_id' => $this->subscription_list_id,
            'segment_id' => $this->segment_id,
            'site_ids' => array_values(array_map(intval(...), $this->site_ids ?? [])),
            'audience_snapshot' => $this->audience_snapshot,
            'estimated_recipients' => $this->estimated_recipients,
            'newsletter_issue_number' => $this->newsletter_issue_number,
            'ignore_send_window' => (bool) $this->ignore_send_window,
            'approval_required' => (bool) $this->approval_required,
            'submitted_by' => $this->submitted_by,
            'submitted_at' => $this->datetime($this->submitted_at),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->datetime($this->approved_at),
            'rejected_reason' => $this->rejected_reason,
            'cancelled_by' => $this->cancelled_by,
            'cancelled_at' => $this->datetime($this->cancelled_at),
            'duplicated_from_id' => $this->duplicated_from_id,
            'created_by' => $this->created_by,
            'creator' => $this->when(
                $this->relationLoaded('creator'),
                fn (): ?array => $this->creator === null ? null : [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                ],
            ),
            'sends' => $this->relationLoaded('sends')
                ? $this->sends->map(fn (CampaignSend $send): array => [
                    'id' => $send->id,
                    'channel' => $send->channel?->value ?? $send->channel,
                    'template_family_id' => $send->template_family_id,
                    'template_version_id' => $send->template_version_id,
                    'version_number' => $send->relationLoaded('templateVersion')
                        ? $send->templateVersion?->version_number
                        : null,
                    'template_family_name' => $send->relationLoaded('templateFamily')
                        ? $send->templateFamily?->name
                        : null,
                    'subject_override' => $send->subject_override,
                    'scheduled_at' => $send->scheduled_at === null ? 'now' : $this->datetime($send->scheduled_at),
                    'completed_at' => $this->datetime($send->completed_at),
                    'status' => $send->status?->value ?? $send->status,
                ])->values()->all()
                : [],
            'counts' => is_array($this->resource->getAttribute('list_counts'))
                ? $this->resource->getAttribute('list_counts')
                : null,
            'open_rate' => $this->resource->getAttribute('list_counts') !== null
                ? $this->resource->getAttribute('open_rate')
                : null,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
