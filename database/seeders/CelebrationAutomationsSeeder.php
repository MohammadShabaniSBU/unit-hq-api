<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use App\Enums\AutomationNodeType;
use App\Enums\AutomationStatus;
use App\Enums\LayoutFieldType;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\Automation;
use App\Models\AutomationEdge;
use App\Models\AutomationNode;
use App\Models\LayoutField;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Support\Communications\EmailBlockDocument;
use App\Support\Filtering\AttributeFieldResolver;
use App\Support\Filtering\FilterSchemaResponder;
use App\Support\Layout\NativeFields;
use Illuminate\Database\Seeder;

/**
 * Draft automations plus email-builder templates.
 *
 * Happy anniversary: contract signed_at update, wait 365 days, send email.
 * Happy birthday: contact Birthday field update, then send email.
 * Both stay draft until an operator activates them.
 */
class CelebrationAutomationsSeeder extends Seeder
{
    public function run(): void
    {
        $birthday = $this->birthdayDefinition();
        $this->placeBirthdayOnContactCard($birthday);
        FilterSchemaResponder::forget(AttributeEntityType::Contact);

        $anniversaryFamily = $this->emailFamily('Happy anniversary', [
            'en' => [
                'subject' => 'Happy anniversary',
                'heading' => 'Happy anniversary',
                'html' => '<p>Hello {{contact.first_name}},</p><p>It has been a year since you moved into unit {{contract.unit_name}}. Thank you for storing with us.</p>',
            ],
            'es' => [
                'subject' => 'Feliz aniversario',
                'heading' => 'Feliz aniversario',
                'html' => '<p>Hola {{contact.first_name}},</p><p>Ha pasado un año desde que se instaló en la unidad {{contract.unit_name}}. Gracias por confiar en nosotros.</p>',
            ],
        ]);

        $birthdayFamily = $this->emailFamily('Happy birthday', [
            'en' => [
                'subject' => 'Happy birthday',
                'heading' => 'Happy birthday',
                'html' => '<p>Hello {{contact.first_name}},</p><p>Wishing you a happy birthday.</p>',
            ],
            'es' => [
                'subject' => 'Feliz cumpleaños',
                'heading' => 'Feliz cumpleaños',
                'html' => '<p>Hola {{contact.first_name}},</p><p>Le deseamos un feliz cumpleaños.</p>',
            ],
        ]);

        $this->automation(
            'Happy anniversary',
            'Sends the Happy anniversary email 365 days after the contract is signed.',
            [
                [
                    'key' => 'trigger',
                    'type' => AutomationNodeType::ObjectUpdated,
                    'label' => 'Contract signed',
                    'config' => [
                        'objectType' => 'contract',
                        'property' => 'signed_at',
                        'conditions' => [
                            ['operator' => 'is_not_empty'],
                        ],
                    ],
                ],
                [
                    'key' => 'wait',
                    'type' => AutomationNodeType::Wait,
                    'label' => 'Wait one year',
                    'config' => [
                        'mode' => 'relative',
                        'amount' => 365,
                        'unit' => 'days',
                        'align' => 'send_window',
                    ],
                ],
                [
                    'key' => 'email',
                    'type' => AutomationNodeType::SendEmail,
                    'label' => 'Happy anniversary email',
                    'config' => $this->emailConfig($anniversaryFamily),
                ],
            ],
            [
                ['trigger', 'wait'],
                ['wait', 'email'],
            ],
        );

        $this->automation(
            'Happy birthday',
            'Sends the Happy birthday email when the contact Birthday field is set.',
            [
                [
                    'key' => 'trigger',
                    'type' => AutomationNodeType::ObjectUpdated,
                    'label' => 'Birthday set',
                    'config' => [
                        'objectType' => 'contact',
                        'property' => AttributeFieldResolver::fieldKey((int) $birthday->id),
                        'conditions' => [
                            ['operator' => 'is_not_empty'],
                        ],
                    ],
                ],
                [
                    'key' => 'email',
                    'type' => AutomationNodeType::SendEmail,
                    'label' => 'Happy birthday email',
                    'config' => $this->emailConfig($birthdayFamily),
                ],
            ],
            [
                ['trigger', 'email'],
            ],
        );
    }

    private function birthdayDefinition(): AttributeDefinition
    {
        $existing = AttributeDefinition::query()
            ->where('entity_type', AttributeEntityType::Contact)
            ->where('key', 'date_of_birth')
            ->first();

        if ($existing instanceof AttributeDefinition) {
            return $existing;
        }

        return AttributeDefinition::query()->create([
            'entity_type' => AttributeEntityType::Contact,
            'key' => 'date_of_birth',
            'label' => 'Birthday',
            'type' => AttributeType::Date,
            'group_name' => 'Personal',
            'display_order' => 8,
        ]);
    }

    private function placeBirthdayOnContactCard(AttributeDefinition $birthday): void
    {
        $placed = LayoutField::query()
            ->where('entity_type', AttributeEntityType::Contact)
            ->where('attribute_definition_id', $birthday->id)
            ->exists();

        if ($placed) {
            return;
        }

        $group = AttributeGroup::query()
            ->where('entity_type', AttributeEntityType::Contact)
            ->where('key', NativeFields::defaultGroupKey(AttributeEntityType::Contact))
            ->first();

        if ($group === null) {
            return;
        }

        $order = (int) LayoutField::query()->where('group_id', $group->id)->max('display_order');

        LayoutField::query()->create([
            'group_id' => $group->id,
            'entity_type' => AttributeEntityType::Contact,
            'display_order' => $order + 1,
            'field_type' => LayoutFieldType::Attribute,
            'native_field_key' => null,
            'attribute_definition_id' => $birthday->id,
        ]);
    }

    /**
     * @param  array<string, array{subject: string, heading: string, html: string}>  $locales
     */
    private function emailFamily(string $name, array $locales): TemplateFamily
    {
        $family = TemplateFamily::query()->firstOrCreate(
            ['name' => $name, 'channel' => TemplateChannel::Email],
            ['purpose' => TemplatePurpose::General],
        );

        foreach ($locales as $locale => $copy) {
            if ($family->variants()->where('locale', $locale)->exists()) {
                continue;
            }

            $blocks = EmailBlockDocument::validate([
                'version' => 1,
                'blocks' => [
                    [
                        'id' => $locale.'-heading',
                        'type' => 'heading',
                        'params' => [
                            'text' => $copy['heading'],
                            'level' => 1,
                        ],
                    ],
                    [
                        'id' => $locale.'-body',
                        'type' => 'paragraph',
                        'params' => [
                            'html' => $copy['html'],
                        ],
                    ],
                ],
            ]);

            TemplateVariant::query()->create([
                'template_family_id' => $family->id,
                'locale' => $locale,
                'subject' => $copy['subject'],
                'blocks' => $blocks,
                'legacy_html' => null,
            ]);
        }

        return $family;
    }

    /** @return array<string, mixed> */
    private function emailConfig(TemplateFamily $family): array
    {
        return [
            'to' => ['kind' => 'dynamic', 'expression' => '{{contact.email}}'],
            'subject' => ['kind' => 'static', 'value' => ''],
            'bodyType' => 'template',
            'templateId' => (string) $family->id,
            'template_family_id' => $family->id,
        ];
    }

    /**
     * @param  list<array{key: string, type: AutomationNodeType, label: string, config: array<string, mixed>}>  $nodes
     * @param  list<array{0: string, 1: string}>  $edges
     */
    private function automation(string $name, string $description, array $nodes, array $edges): void
    {
        $existing = Automation::query()->where('name', $name)->whereNull('playbook_id')->first();
        if ($existing !== null) {
            return;
        }

        $automation = Automation::query()->create([
            'name' => $name,
            'description' => $description,
            'status' => AutomationStatus::Draft,
            'version' => 1,
            'single_active_run_per_subject' => true,
        ]);

        $created = [];
        foreach ($nodes as $index => $spec) {
            $type = $spec['type'];
            $created[$spec['key']] = AutomationNode::query()->create([
                'automation_id' => $automation->id,
                'node_key' => $spec['key'],
                'kind' => $type->kind(),
                'type' => $type,
                'label' => $spec['label'],
                'position_x' => $index * 280,
                'position_y' => 0,
                'config' => $spec['config'],
            ]);
        }

        foreach ($edges as $edge) {
            [$from, $to] = $edge;
            AutomationEdge::query()->create([
                'automation_id' => $automation->id,
                'source_node_id' => $created[$from]->id,
                'target_node_id' => $created[$to]->id,
                'source_handle' => 'default',
                'condition' => ['type' => 'always'],
            ]);
        }
    }
}
