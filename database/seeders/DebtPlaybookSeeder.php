<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PlaybookKind;
use App\Enums\PlaybookStepAction;
use App\Models\EmailBlock;
use App\Models\EmailTemplate;
use App\Models\Playbook;
use App\Models\PlaybookStep;
use Illuminate\Database\Seeder;

/**
 * Inactive default debt process: D0 email, D2 SMS, D4 email+notice, D7 urgent task.
 * Operator reviews and activates. Payment-link generation is an S10 gap (placeholder token).
 */
class DebtPlaybookSeeder extends Seeder
{
    public function run(): void
    {
        $template = EmailTemplate::query()->firstOrCreate(
            ['name' => 'Payment reminder'],
        );

        if ($template->emailBlocks()->count() === 0) {
            EmailBlock::query()->create([
                'email_template_id' => $template->id,
                'type' => 'text',
                'props' => [
                    'content' => 'Hello {{contact.first_name}}, your balance of {{contract.balance_owed}} is overdue. Pay here: {{pay_link}}',
                    'align' => 'left',
                    'fontSize' => 16,
                    'color' => '#000000',
                ],
                'order' => 0,
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
                    'email_template_id' => $template->id,
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
                    'email_template_id' => $template->id,
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
