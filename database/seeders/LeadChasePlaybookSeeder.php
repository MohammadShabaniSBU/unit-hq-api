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
 * Inactive default lead chase: D0 email, D1 task, D3 SMS, D7 email.
 */
class LeadChasePlaybookSeeder extends Seeder
{
    public function run(): void
    {
        $thanks = TemplateFamily::query()->firstOrCreate(
            ['name' => 'Enquiry thanks', 'channel' => TemplateChannel::Email],
            ['purpose' => TemplatePurpose::Lead],
        );

        if ($thanks->variants()->count() === 0) {
            $legacyHtml = LegacyEmailBlocksHtml::fromBlocks([[
                'type' => 'text',
                'props' => [
                    'content' => 'Hola {{contact.first_name}}, gracias por su consulta. Estamos aquí para ayudarle a encontrar el trastero ideal.',
                    'align' => 'left',
                    'fontSize' => 16,
                    'color' => '#000000',
                ],
            ]]);

            TemplateVariant::query()->create([
                'template_family_id' => $thanks->id,
                'locale' => 'es',
                'subject' => 'Gracias por su consulta',
                'legacy_html' => $legacyHtml,
            ]);
        }

        $offers = TemplateFamily::query()->firstOrCreate(
            ['name' => 'Offers this month', 'channel' => TemplateChannel::Email],
            ['purpose' => TemplatePurpose::Lead],
        );

        if ($offers->variants()->count() === 0) {
            $legacyHtml = LegacyEmailBlocksHtml::fromBlocks([[
                'type' => 'text',
                'props' => [
                    'content' => 'Hola {{contact.first_name}}, este mes tenemos disponibilidad y ofertas en varias medidas. ¿Quiere que le enviemos una propuesta?',
                    'align' => 'left',
                    'fontSize' => 16,
                    'color' => '#000000',
                ],
            ]]);

            TemplateVariant::query()->create([
                'template_family_id' => $offers->id,
                'locale' => 'es',
                'subject' => 'Ofertas de este mes',
                'legacy_html' => $legacyHtml,
            ]);
        }

        $existing = Playbook::query()
            ->where('kind', PlaybookKind::LeadChase)
            ->where('name', 'Default lead chase')
            ->first();

        if ($existing !== null) {
            return;
        }

        $playbook = Playbook::query()->create([
            'kind' => PlaybookKind::LeadChase,
            'name' => 'Default lead chase',
            'is_active' => false,
            'enrolment_filters' => [],
        ]);

        $steps = [
            [
                'offset_days' => 0,
                'action' => PlaybookStepAction::SendEmail,
                'params' => [
                    'label' => 'Thanks for enquiry',
                    'template_family_id' => $thanks->id,
                    'subject' => 'Gracias por su consulta',
                ],
            ],
            [
                'offset_days' => 1,
                'action' => PlaybookStepAction::CreateTask,
                'params' => [
                    'title' => 'Call the lead',
                    'urgent' => false,
                ],
            ],
            [
                'offset_days' => 3,
                'action' => PlaybookStepAction::SendSms,
                'params' => [
                    'label' => 'Still looking?',
                    'body' => 'Hola, ¿sigue buscando trastero? Estamos para ayudarle.',
                    'tokens' => true,
                ],
            ],
            [
                'offset_days' => 7,
                'action' => PlaybookStepAction::SendEmail,
                'params' => [
                    'label' => 'Offers this month',
                    'template_family_id' => $offers->id,
                    'subject' => 'Ofertas de este mes',
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
