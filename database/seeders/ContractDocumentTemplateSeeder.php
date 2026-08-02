<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Default contract document family with es + en variants (S14-01).
 */
class ContractDocumentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $family = TemplateFamily::query()->firstOrCreate(
            ['name' => 'Self-storage rental agreement', 'channel' => TemplateChannel::Document],
            ['purpose' => TemplatePurpose::Contract],
        );

        if ($family->purpose !== TemplatePurpose::Contract) {
            $family->update(['purpose' => TemplatePurpose::Contract]);
        }

        $this->seedVariant($family, 'en', 'Self-storage rental agreement', [
            'heading' => 'Agreement',
            'intro' => 'This agreement is entered into between the landlord and the tenant named below for the self-storage unit(s) described in the terms table.',
            'obligations_heading' => 'Tenant obligations',
            'obligations_body' => "The tenant ({{contact.name}}) agrees to pay rent as specified, keep the unit secure, and give notice before vacating.\nGoods are stored at the tenant's risk unless insurance cover is separately agreed.",
            'law_heading' => 'Governing law',
            'law_body' => 'This agreement is governed by the laws applicable at the site where the unit is located.',
        ]);

        $this->seedVariant($family, 'es', 'Contrato de alquiler de trastero', [
            'heading' => 'Contrato',
            'intro' => 'El presente contrato se celebra entre el arrendador y el inquilino identificados a continuación para la(s) unidad(es) de trastero descritas en la tabla de condiciones.',
            'obligations_heading' => 'Obligaciones del inquilino',
            'obligations_body' => "El inquilino ({{contact.name}}) se compromete a pagar el alquiler indicado, mantener la unidad segura y preavisar antes de la salida.\nLos bienes se almacenan bajo riesgo del inquilino salvo seguro acordado por separado.",
            'law_heading' => 'Legislación aplicable',
            'law_body' => 'Este contrato se rige por la legislación aplicable en el centro donde se encuentra la unidad.',
        ]);
    }

    /**
     * @param  array{heading: string, intro: string, obligations_heading: string, obligations_body: string, law_heading: string, law_body: string}  $copy
     */
    private function seedVariant(TemplateFamily $family, string $locale, string $subject, array $copy): void
    {
        if ($family->variants()->where('locale', $locale)->exists()) {
            return;
        }

        TemplateVariant::query()->create([
            'template_family_id' => $family->id,
            'locale' => $locale,
            'subject' => $subject,
            'blocks' => [
                'version' => 1,
                'blocks' => [
                    [
                        'id' => (string) Str::uuid(),
                        'type' => 'legal_section',
                        'params' => [
                            'heading' => $copy['heading'],
                            'body' => $copy['intro'],
                        ],
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'type' => 'parties',
                        'params' => [],
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'type' => 'terms_table',
                        'params' => [],
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'type' => 'legal_section',
                        'params' => [
                            'heading' => $copy['obligations_heading'],
                            'body' => $copy['obligations_body'],
                        ],
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'type' => 'page_break',
                        'params' => [],
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'type' => 'legal_section',
                        'params' => [
                            'heading' => $copy['law_heading'],
                            'body' => $copy['law_body'],
                        ],
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'type' => 'signature_anchor',
                        'params' => [],
                    ],
                ],
            ],
        ]);
    }
}
