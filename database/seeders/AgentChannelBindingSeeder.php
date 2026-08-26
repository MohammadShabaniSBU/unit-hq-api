<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AgentChannelBinding;
use App\Models\AiAgent;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\BindingAudience;
use App\Support\Ai\Enums\BindingMode;
use App\Support\Ai\Enums\OutsideHoursPolicy;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class AgentChannelBindingSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $sales = AiAgent::query()->where('key', 'sales')->first();
        $support = AiAgent::query()->where('key', 'support')->first();

        if ($sales === null || $support === null) {
            throw new RuntimeException('AiAgentSeeder must run before AgentChannelBindingSeeder.');
        }

        $rows = [
            [
                'ai_agent_id' => $sales->id,
                'channel' => AgentChannel::Webchat,
                'mode' => BindingMode::Auto,
                'audience' => BindingAudience::All,
                'outside_hours' => OutsideHoursPolicy::Answer,
            ],
            [
                'ai_agent_id' => $sales->id,
                'channel' => AgentChannel::Sms,
                'mode' => BindingMode::Draft,
                'audience' => BindingAudience::KnownContacts,
                'outside_hours' => OutsideHoursPolicy::Inbox,
            ],
            [
                'ai_agent_id' => $sales->id,
                'channel' => AgentChannel::Whatsapp,
                'mode' => BindingMode::Draft,
                'audience' => BindingAudience::KnownContacts,
                'outside_hours' => OutsideHoursPolicy::Inbox,
            ],
            [
                'ai_agent_id' => $support->id,
                'channel' => AgentChannel::Email,
                'mode' => BindingMode::Draft,
                'audience' => BindingAudience::ExistingTenants,
                'outside_hours' => OutsideHoursPolicy::Inbox,
            ],
        ];

        usort(
            $rows,
            static fn (array $a, array $b): int => $a['channel']->value <=> $b['channel']->value,
        );

        foreach ($rows as $row) {
            AgentChannelBinding::query()->updateOrCreate(
                [
                    'channel' => $row['channel'],
                    'site_id' => null,
                ],
                [
                    'ai_agent_id' => $row['ai_agent_id'],
                    'mode' => $row['mode'],
                    'audience' => $row['audience'],
                    'outside_hours' => $row['outside_hours'],
                    'archived_at' => null,
                ],
            );
        }
    }
}
