<?php

declare(strict_types=1);

namespace App\Support\Fiscal;

use App\Models\Invoice;
use App\Support\Time\DateFormat;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\View;

/**
 * HTML-first invoice render. PDF is behind this class so the engine can swap.
 * Templates receive arrays only — never Eloquent models.
 */
final class InvoiceRenderer
{
    /**
     * Build a snapshot-only payload from an issued invoice.
     *
     * @return array<string, mixed>
     */
    public static function payloadFromInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing(['lines', 'rectifiesInvoice']);

        $kind = $invoice->kind?->value ?? (string) $invoice->kind;
        $locale = self::localeFromIssuerCountry(
            is_array($invoice->issuer_address) ? ($invoice->issuer_address['country'] ?? 'ES') : 'ES'
        );

        $taxBreakdown = [];
        foreach ($invoice->lines as $line) {
            $rate = (string) $line->tax_rate_snapshot;
            if (! isset($taxBreakdown[$rate])) {
                $taxBreakdown[$rate] = ['rate' => $rate, 'net' => '0.00', 'tax' => '0.00'];
            }
            $taxBreakdown[$rate]['net'] = bcadd($taxBreakdown[$rate]['net'], (string) $line->net_amount, 2);
            $taxBreakdown[$rate]['tax'] = bcadd($taxBreakdown[$rate]['tax'], (string) $line->tax_amount, 2);
        }

        return [
            'full_number' => $invoice->full_number,
            'kind' => $kind,
            'kind_label' => trans('invoices.kinds.'.$kind, [], $locale),
            'issue_date' => $invoice->issue_date
                ? DateFormat::display($invoice->issue_date)
                : null,
            'currency' => $invoice->currency,
            'locale' => $locale,
            'rectifies_full_number' => $invoice->rectifiesInvoice?->full_number,
            'labels' => [
                'net' => trans('invoices.labels.net', [], $locale),
                'tax' => trans('invoices.labels.tax', [], $locale),
                'total' => trans('invoices.labels.total', [], $locale),
                'issue_date' => trans('invoices.labels.issue_date', [], $locale),
                'number' => trans('invoices.labels.number', [], $locale),
                'issuer' => trans('invoices.labels.issuer', [], $locale),
                'buyer' => trans('invoices.labels.buyer', [], $locale),
                'period' => trans('invoices.labels.period', [], $locale),
                'description' => trans('invoices.labels.description', [], $locale),
                'qr_placeholder' => trans('invoices.labels.qr_placeholder', [], $locale),
                'rectifies' => trans('invoices.labels.rectifies', [], $locale),
            ],
            'issuer' => [
                'name' => $invoice->issuer_name,
                'tax_id' => $invoice->issuer_tax_id,
                'address' => $invoice->issuer_address ?? [],
            ],
            'buyer' => [
                'name' => $invoice->buyer_name,
                'tax_id' => $invoice->buyer_tax_id,
                'address' => $invoice->buyer_address,
            ],
            'lines' => $invoice->lines->map(fn ($line) => [
                'description' => $line->description,
                'period_start' => $line->period_start
                    ? DateFormat::display($line->period_start)
                    : null,
                'period_end' => $line->period_end
                    ? DateFormat::display($line->period_end)
                    : null,
                'net_amount' => (string) $line->net_amount,
                'tax_rate_snapshot' => (string) $line->tax_rate_snapshot,
                'tax_amount' => (string) $line->tax_amount,
                'gross_amount' => (string) $line->gross_amount,
            ])->values()->all(),
            'tax_breakdown' => array_values($taxBreakdown),
            'net_total' => (string) $invoice->net_total,
            'tax_total' => (string) $invoice->tax_total,
            'gross_total' => (string) $invoice->gross_total,
            'qr_placeholder' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function html(array $payload): string
    {
        return View::make('invoices.fiscal', ['invoice' => $payload])->render();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function pdf(array $payload): string
    {
        return Pdf::loadHTML(self::html($payload))->output();
    }

    private static function localeFromIssuerCountry(string $countryCode): string
    {
        return match (strtoupper($countryCode)) {
            'ES' => 'es',
            'FR' => 'fr',
            default => 'en',
        };
    }
}
