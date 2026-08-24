<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Enums\TemplatePurpose;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Insurance;
use App\Models\LegalEntity;
use App\Models\TemplateVariant;
use App\Models\Unit;
use App\Support\Automation\TokenResolver;
use App\Support\Communications\TemplateBuilderContext;
use App\Support\ESign\ESignProviderRegistry;
use App\Support\Fiscal\InvoiceIssuer;
use App\Support\Time\DateFormat;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;

/**
 * Snapshot-in, bytes-out contract document renderer (InvoiceRenderer idiom).
 * Templates receive arrays only — never Eloquent models.
 */
final class ContractDocumentRenderer
{
    /**
     * @return array{pdf_bytes: string, html: string, payload: array<string, mixed>}
     */
    public static function render(Contract $contract, TemplateVariant $variant): array
    {
        $payload = self::payload($contract, $variant);
        $html = self::html($payload);
        $pdfBytes = Pdf::loadHTML($html)
            ->setPaper('a4')
            ->output();

        return [
            'pdf_bytes' => $pdfBytes,
            'html' => $html,
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(Contract $contract, TemplateVariant $variant): array
    {
        $variant->loadMissing('family');
        $purpose = $variant->family?->purpose instanceof TemplatePurpose
            ? $variant->family->purpose
            : ($variant->family?->purpose !== null
                ? TemplatePurpose::from((string) $variant->family->purpose)
                : TemplatePurpose::Contract);

        $doc = DocumentBlockDocument::validateForRender($variant->blocks, $purpose);

        $contract->loadMissing([
            'contact',
            'unitItem.item.site.legalEntity',
            'items.price',
            'items.item',
        ]);

        $contact = $contract->contact;
        if (! $contact instanceof Contact) {
            throw ValidationException::withMessages([
                'contract' => ['Contract has no contact.'],
            ]);
        }

        $context = TemplateBuilderContext::for($contact, $contract);
        $locale = $variant->locale;
        $signatureToken = app(ESignProviderRegistry::class)->signatureAnchor();

        $legalSectionNumber = 0;
        $blocks = [];
        foreach ($doc['blocks'] as $block) {
            $type = $block['type'];
            $params = $block['params'];

            $rendered = match ($type) {
                'heading' => [
                    'type' => 'heading',
                    'text' => TokenResolver::resolve((string) ($params['text'] ?? ''), $context),
                    'level' => (int) ($params['level'] ?? 1),
                ],
                'paragraph' => [
                    'type' => 'paragraph',
                    'html' => TokenResolver::resolve((string) ($params['html'] ?? ''), $context),
                ],
                'divider' => ['type' => 'divider'],
                'spacer' => [
                    'type' => 'spacer',
                    'height' => (int) ($params['height'] ?? 24),
                ],
                'legal_section' => (static function () use (&$legalSectionNumber, $params, $context): array {
                    $legalSectionNumber++;

                    return [
                        'type' => 'legal_section',
                        'number' => $legalSectionNumber,
                        'heading' => TokenResolver::resolve((string) ($params['heading'] ?? ''), $context),
                        'body' => TokenResolver::resolve((string) ($params['body'] ?? ''), $context),
                    ];
                })(),
                'parties' => [
                    'type' => 'parties',
                    'issuer' => self::issuerParty($contract),
                    'tenant' => self::tenantParty($contact),
                    'labels' => [
                        'issuer' => trans('documents.labels.issuer', [], $locale),
                        'tenant' => trans('documents.labels.tenant', [], $locale),
                        'incomplete' => trans('documents.labels.identity_incomplete', [], $locale),
                    ],
                ],
                'terms_table' => [
                    'type' => 'terms_table',
                    'terms' => self::termsTable($contract),
                    'labels' => [
                        'unit' => trans('documents.labels.unit', [], $locale),
                        'amount' => trans('documents.labels.amount', [], $locale),
                        'deposit' => trans('documents.labels.deposit', [], $locale),
                        'move_in' => trans('documents.labels.move_in', [], $locale),
                        'notice_period' => trans('documents.labels.notice_period', [], $locale),
                        'cadence' => trans('documents.labels.cadence', [], $locale),
                        'days' => trans('documents.labels.days', [], $locale),
                    ],
                ],
                'signature_anchor' => [
                    'type' => 'signature_anchor',
                    'token' => $signatureToken,
                    'label' => trans('documents.labels.signature', [], $locale),
                ],
                'page_break' => ['type' => 'page_break'],
                default => ['type' => $type],
            };

            $blocks[] = $rendered;
        }

        return [
            'locale' => $locale,
            'title' => $variant->subject ?: ($variant->family?->name ?? 'Contract'),
            'labels' => [
                'page' => trans('documents.labels.page', [], $locale),
                'of' => trans('documents.labels.of', [], $locale),
            ],
            'blocks' => $blocks,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function html(array $payload): string
    {
        return View::make('documents.contract', ['document' => $payload])->render();
    }

    /**
     * @return array{name: string, tax_id: string|null, address: array<string, mixed>}
     */
    private static function issuerParty(Contract $contract): array
    {
        $contract->loadMissing('unitItem.item.site.legalEntity');
        $item = $contract->unitItem?->item;
        $legalEntity = $item instanceof Unit ? $item->site?->legalEntity : null;

        if (! $legalEntity instanceof LegalEntity) {
            return [
                'name' => '',
                'tax_id' => null,
                'address' => [],
            ];
        }

        return [
            'name' => $legalEntity->legal_name,
            'tax_id' => $legalEntity->tax_id,
            'address' => InvoiceIssuer::issuerAddress($legalEntity),
        ];
    }

    /**
     * @return array{name: string, tax_id: string|null, address: array<string, mixed>|null, incomplete: bool}
     */
    private static function tenantParty(Contact $contact): array
    {
        if ($contact->fiscalComplete()) {
            return [
                'name' => InvoiceIssuer::buyerName($contact),
                'tax_id' => $contact->tax_id,
                'address' => InvoiceIssuer::buyerAddress($contact),
                'incomplete' => false,
            ];
        }

        return [
            'name' => trim((string) $contact->first_name.' '.(string) $contact->last_name),
            'tax_id' => $contact->tax_id,
            'address' => [
                'line1' => $contact->billing_address_line1,
                'line2' => $contact->billing_address_line2,
                'city' => $contact->billing_city,
                'postal' => $contact->billing_postal_code,
                'country' => $contact->billing_country_code,
            ],
            'incomplete' => true,
        ];
    }

    /**
     * @return array{
     *     items: list<array{label: string, amount: string, currency: string}>,
     *     deposit: string,
     *     currency: string,
     *     move_in: string|null,
     *     notice_period_days: int|null,
     *     cadence: string
     * }
     */
    private static function termsTable(Contract $contract): array
    {
        $moveIn = $contract->move_in_date;
        $items = $contract->itemsOn($moveIn ?? now());

        $rows = [];
        foreach ($items as $item) {
            $item->loadMissing(['price', 'item']);
            $subject = $item->item;
            $label = match (true) {
                $subject instanceof Unit => (string) $subject->unit_number,
                $subject instanceof Insurance => (string) ($subject->name ?? 'Insurance'),
                default => (string) ($item->description ?? $item->item_type),
            };
            $amount = $item->base_rate !== null
                ? (string) $item->base_rate
                : (string) ($item->price?->amount ?? '0.00');

            $rows[] = [
                'label' => $label,
                'amount' => $amount,
                'currency' => (string) $contract->currency,
            ];
        }

        $interval = $contract->billing_interval?->value ?? (string) $contract->billing_interval;
        $count = (int) $contract->billing_interval_count;
        $cadence = $count === 1 ? $interval : "{$count} {$interval}";

        return [
            'items' => $rows,
            'deposit' => (string) $contract->deposit_amount,
            'currency' => (string) $contract->currency,
            'move_in' => $moveIn ? DateFormat::display($moveIn) : null,
            'notice_period_days' => $contract->notice_period_days,
            'cadence' => $cadence,
        ];
    }
}
