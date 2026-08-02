<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PlaybookKind;
use App\Enums\PlaybookStepAction;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\Playbook;
use App\Models\PlaybookStep;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Support\Communications\LegacyEmailBlocksHtml;
use Illuminate\Database\Seeder;

/**
 * Inactive default debt process: D0 email, D2 SMS, D4 email+notice, D7 urgent task.
 * Operator reviews and activates. Payment-link generation is an S10 gap (placeholder token).
 */
class DebtPlaybookSeeder extends Seeder
{
    public function run(): void
    {
        $family = TemplateFamily::query()->firstOrCreate(
            ['name' => 'Payment reminder', 'channel' => TemplateChannel::Email],
            ['purpose' => TemplatePurpose::Debt],
        );

        if ($family->variants()->count() === 0) {
            $legacyHtml = LegacyEmailBlocksHtml::fromBlocks([[
                'type' => 'text',
                'props' => [
                    'content' => 'Hello {{contact.first_name}}, your balance of {{contract.balance_owed}} is overdue. Pay here: {{pay_link}}',
                    'align' => 'left',
                    'fontSize' => 16,
                    'color' => '#000000',
                ],
            ]]);

            TemplateVariant::query()->create([
                'template_family_id' => $family->id,
                'locale' => 'en',
                'subject' => 'Payment reminder',
                'legacy_html' => $legacyHtml,
            ]);
        }

        $existing = Playbook::query()
            ->where('kind', PlaybookKind::DebtProcess)
            ->where('name', 'Default debt process')
            ->first();

        if ($existing !== null) {
            return;
        }

        $playbook = Playbook::query()->create([
            'kind' => PlaybookKind::DebtProcess,
            'name' => 'Default debt process',
            'is_active' => false,
            'enrolment_filters' => [],
        ]);

        $steps = [
            [
                'offset_days' => 0,
                'action' => PlaybookStepAction::SendEmail,
                'params' => [
                    'label' => 'Payment reminder',
                    'template_family_id' => $family->id,
                    'subject' => 'Payment reminder',
                ],
            ],
            [
                'offset_days' => 2,
                'action' => PlaybookStepAction::SendSms,
                'params' => [
                    'label' => 'SMS nudge',
                    'body' => 'Reminder: your storage balance is overdue. Please pay when you can.',
                    'tokens' => true,
                ],
            ],
            [
                'offset_days' => 4,
                'action' => PlaybookStepAction::SendEmail,
                'params' => [
                    'label' => 'Overdue notice email',
                    'template_family_id' => $family->id,
                    'subject' => 'Overdue balance notice',
                    'record_notice' => 'overdue',
                ],
            ],
            [
                'offset_days' => 7,
                'action' => PlaybookStepAction::CreateTask,
                'params' => [
                    'title' => 'Call the tenant',
                    'urgent' => true,
                ],
            ],
        ];

        foreach ($steps as $index => $step) {
            PlaybookStep::query()->create([
                'playbook_id' => $playbook->id,
                'offset_days' => $step['offset_days'],
                'action' => $step['action'],
                'params' => $step['params'],
                'sort' => $index,
            ]);
        }
    }
}
