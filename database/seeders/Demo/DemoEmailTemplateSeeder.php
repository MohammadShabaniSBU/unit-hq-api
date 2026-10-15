<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\TemplateFamily;
use Database\Factories\TemplateFamilyFactory;
use Database\Support\EmailDocBuilder;
use Illuminate\Database\Seeder;

/**
 * Published v2 demo emails that exercise columns, callouts, and brand blocks.
 * Idempotent by family name. Block ids come from EmailDocBuilder.
 */
class DemoEmailTemplateSeeder extends Seeder
{
    private const ADDRESS = 'Calle de Fuencarral 45, 28004 Madrid';

    /** @var list<array{network: string, url: string}> */
    private const LINKS = [
        ['network' => 'website', 'url' => 'https://keevaris.example'],
        ['network' => 'instagram', 'url' => 'https://www.instagram.com/keevaris'],
    ];

    public function run(): void
    {
        $this->rateIncrease();
        $this->overdueReminder();
    }

    private function rateIncrease(): void
    {
        $this->publish('Rate increase notice', TemplatePurpose::System, [
            'en' => [
                'subject' => 'Rate increase notice',
                'heading' => 'Your rate is changing',
                'paragraph' => '<p>Hello {{contact.first_name}}, the rate for unit {{contract.unit_name}} is changing.</p>',
                'current_label' => 'Current rate',
                'new_label' => 'New rate',
                'date_label' => 'Effective date',
                'date' => '1 November 2026',
                'button' => 'View my contract',
            ],
            'es' => [
                'subject' => 'Aviso de subida de tarifa',
                'heading' => 'Su tarifa cambia',
                'paragraph' => '<p>Hola {{contact.first_name}}, la tarifa de la unidad {{contract.unit_name}} cambia.</p>',
                'current_label' => 'Tarifa actual',
                'new_label' => 'Tarifa nueva',
                'date_label' => 'Fecha de efecto',
                'date' => '1 de noviembre de 2026',
                'button' => 'Ver mi contrato',
            ],
        ], 'rate');
    }

    private function overdueReminder(): void
    {
        $this->publish('Overdue reminder', TemplatePurpose::Debt, [
            'en' => [
                'subject' => 'Overdue reminder',
                'title' => 'Overdue balance',
                'html' => '<p>Your balance of {{contract.balance_owed}} is overdue.</p>',
                'unit_label' => 'Unit',
                'amount_label' => 'Amount',
                'date_label' => 'Due date',
                'date' => '15 October 2026',
                'button' => 'Pay now',
            ],
            'es' => [
                'subject' => 'Recordatorio de impago',
                'title' => 'Saldo vencido',
                'html' => '<p>Su saldo de {{contract.balance_owed}} está vencido.</p>',
                'unit_label' => 'Unidad',
                'amount_label' => 'Importe',
                'date_label' => 'Fecha de vencimiento',
                'date' => '15 de octubre de 2026',
                'button' => 'Pagar ahora',
            ],
        ], 'overdue');
    }

    /**
     * @param  array<string, array<string, string>>  $locales
     */
    private function publish(string $name, TemplatePurpose $purpose, array $locales, string $kind): void
    {
        $existing = TemplateFamily::query()
            ->where('name', $name)
            ->where('channel', TemplateChannel::Email)
            ->first();

        if ($existing !== null) {
            return;
        }

        $variants = [];
        foreach ($locales as $locale => $copy) {
            $variants[] = [
                'locale' => $locale,
                'subject' => $copy['subject'],
                'blocks' => $kind === 'rate'
                    ? $this->rateDocument($name.':'.$locale, $copy)
                    : $this->overdueDocument($name.':'.$locale, $copy),
                'legacy_html' => null,
            ];
        }

        TemplateFamilyFactory::published([
            'name' => $name,
            'channel' => TemplateChannel::Email,
            'purpose' => $purpose,
        ], $variants);
    }

    /**
     * @param  array<string, string>  $copy
     * @return array<string, mixed>
     */
    private function rateDocument(string $seed, array $copy): array
    {
        return EmailDocBuilder::make($seed)
            ->section('100', function ($column) use ($copy): void {
                $column->logoHeader('center', 160, null)
                    ->heading($copy['heading'], 1)
                    ->paragraph($copy['paragraph']);
            })
            ->section(
                '50_50',
                fn ($column) => $column->infoRows([
                    ['label' => $copy['current_label'], 'value' => '{{contract.unit_rate}}'],
                ], true, 'right'),
                fn ($column) => $column->infoRows([
                    ['label' => $copy['new_label'], 'value' => '96.00'],
                    ['label' => $copy['date_label'], 'value' => $copy['date']],
                ], true, 'right'),
            )
            ->section('100', function ($column) use ($copy): void {
                $column->button($copy['button'], 'https://example.test/contracts/1', 'primary')
                    ->footerSocial(self::LINKS, self::ADDRESS);
            })
            ->toArray();
    }

    /**
     * @param  array<string, string>  $copy
     * @return array<string, mixed>
     */
    private function overdueDocument(string $seed, array $copy): array
    {
        return EmailDocBuilder::make($seed)
            ->section('100', function ($column) use ($copy): void {
                $column->logoHeader('center', 160, null)
                    ->callout('warning', $copy['title'], $copy['html'])
                    ->infoRows([
                        ['label' => $copy['unit_label'], 'value' => '{{contract.unit_name}}'],
                        ['label' => $copy['amount_label'], 'value' => '{{contract.balance_owed}}'],
                        ['label' => $copy['date_label'], 'value' => $copy['date']],
                    ], true, 'right')
                    ->button($copy['button'], '{{pay_link}}', 'primary')
                    ->footerSocial(self::LINKS, self::ADDRESS);
            })
            ->toArray();
    }
}
