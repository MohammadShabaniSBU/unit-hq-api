<?php

declare(strict_types=1);

namespace App\Support\Fiscal;

use App\Enums\ChargeType;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceSeries;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\Unit;
use App\Support\RecordsActivity;
use App\Support\Time\SiteClock;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Sole write path for issued fiscal invoices. Must run inside the caller's
 * DB transaction so series allocation and charge stamps commit together.
 */
final class InvoiceIssuer
{
    /**
     * Issue an invoice for the given charges on a contract.
     * Returns null when the filtered charge set is empty (no number consumed).
     *
     * @param  Collection<int, Charge>  $charges
     */
    public static function issue(
        Contract $contract,
        Collection $charges,
        ?InvoiceSeries $series = null,
        ?int $createdBy = null,
    ): ?Invoice {
        $contract->loadMissing(['contact', 'unitItem.item.site.country', 'unitItem.item.site.legalEntity']);

        $site = self::resolveSite($contract);
        $entity = self::resolveLegalEntity($site);
        $contact = $contract->contact;
        if ($contact === null) {
            throw ValidationException::withMessages([
                'contact' => [__('errors.invoices.missing_contact')],
            ]);
        }

        $eligible = self::filterCharges($contract, $charges);
        if ($eligible->isEmpty()) {
            return null;
        }

        $kind = self::determineKind($contact);
        $grossTotal = self::sumGross($eligible);
        self::assertSimplifiedLimit($kind, $grossTotal);

        $series ??= self::defaultSeries($entity, $kind);
        InvoiceNumbering::assertKind($series, $kind->value);

        $number = InvoiceNumbering::allocate($series);
        $fullNumber = sprintf('%s-%06d', $series->code, $number);
        $locale = self::localeForSite($site);
        $issueDate = SiteClock::today($site)->toDateString();

        $netTotal = '0.00';
        $taxTotal = '0.00';
        foreach ($eligible as $charge) {
            $netTotal = bcadd($netTotal, (string) ($charge->net_amount ?? '0.00'), 2);
            $taxTotal = bcadd($taxTotal, (string) $charge->tax_amount, 2);
        }

        $assertGross = bcadd($netTotal, $taxTotal, 2);
        if (bccomp($assertGross, $grossTotal, 2) !== 0) {
            throw new RuntimeException('Invoice totals do not match charge snapshots.');
        }

        $invoice = Invoice::query()->create([
            'legal_entity_id' => $entity->id,
            'invoice_series_id' => $series->id,
            'number' => $number,
            'full_number' => $fullNumber,
            'kind' => $kind,
            'status' => InvoiceStatus::Issued,
            'issue_date' => $issueDate,
            'contract_id' => $contract->id,
            'contact_id' => $contact->id,
            'issuer_name' => $entity->legal_name,
            'issuer_tax_id' => $entity->tax_id,
            'issuer_address' => self::issuerAddress($entity),
            'buyer_name' => self::buyerName($contact),
            'buyer_tax_id' => $kind === InvoiceKind::Ordinary ? $contact->tax_id : null,
            'buyer_address' => $kind === InvoiceKind::Ordinary ? self::buyerAddress($contact) : null,
            'currency' => (string) $contract->currency,
            'net_total' => $netTotal,
            'tax_total' => $taxTotal,
            'gross_total' => $grossTotal,
            'created_by' => $createdBy,
        ]);

        $lineNet = '0.00';
        $lineTax = '0.00';
        $lineGross = '0.00';

        foreach ($eligible as $charge) {
            $net = (string) ($charge->net_amount ?? '0.00');
            $tax = (string) $charge->tax_amount;
            $gross = (string) $charge->amount;
            $rate = (string) ($charge->tax_rate_snapshot ?? '0.00');

            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id,
                'charge_id' => $charge->id,
                'description' => self::lineDescription($charge, $contract, $locale),
                'period_start' => $charge->period_start?->toDateString(),
                'period_end' => $charge->period_end?->toDateString(),
                'net_amount' => $net,
                'tax_rate_snapshot' => $rate,
                'tax_amount' => $tax,
                'gross_amount' => $gross,
            ]);

            $lineNet = bcadd($lineNet, $net, 2);
            $lineTax = bcadd($lineTax, $tax, 2);
            $lineGross = bcadd($lineGross, $gross, 2);

            // One-way stamp — charges are otherwise append-only; invoice_id is the
            // sole permitted null→id write (invariant 34d).
            Charge::query()->whereKey($charge->id)->update(['invoice_id' => $invoice->id]);
            $charge->invoice_id = $invoice->id;
        }

        if (
            bccomp($lineNet, $netTotal, 2) !== 0
            || bccomp($lineTax, $taxTotal, 2) !== 0
            || bccomp($lineGross, $grossTotal, 2) !== 0
        ) {
            throw new RuntimeException('Invoice line sums do not match invoice totals.');
        }

        RecordsActivity::core('invoice.issued', $invoice, [
            'full_number' => $fullNumber,
            'kind' => $kind->value,
            'contract_id' => $contract->id,
            'contact_id' => $contact->id,
            'net_total' => $netTotal,
            'tax_total' => $taxTotal,
            'gross_total' => $grossTotal,
            'currency' => (string) $contract->currency,
            'charge_ids' => $eligible->pluck('id')->values()->all(),
        ]);

        return $invoice->load('lines');
    }

    /**
     * Read-only preview of what would be issued (no allocate, no writes).
     *
     * @return array{invoice_kind: string, invoice_blocker: string|null, gross_total: string, net_total: string, tax_total: string}
     */
    public static function previewForContact(Contact $contact, string $grossTotal, string $netTotal = '0.00', string $taxTotal = '0.00'): array
    {
        $kind = self::determineKind($contact);
        $blocker = null;

        if ($kind === InvoiceKind::Simplified) {
            $limit = (string) config('fiscal.simplified_gross_limit', '400.00');
            if (bccomp($grossTotal, $limit, 2) > 0) {
                $blocker = 'simplified_limit_exceeded';
            }
        }

        return [
            'invoice_kind' => $kind->value,
            'invoice_blocker' => $blocker,
            'gross_total' => $grossTotal,
            'net_total' => $netTotal,
            'tax_total' => $taxTotal,
        ];
    }

    /**
     * @param  Collection<int, Charge>  $charges
     * @return Collection<int, Charge>
     */
    public static function filterCharges(Contract $contract, Collection $charges): Collection
    {
        $invoiceDeposits = (bool) config('fiscal.invoice_deposits', false);

        return $charges
            ->filter(function (Charge $charge) use ($contract, $invoiceDeposits): bool {
                if ((int) $charge->contract_id !== (int) $contract->id) {
                    return false;
                }
                if ($charge->invoice_id !== null) {
                    return false;
                }

                $type = $charge->charge_type instanceof ChargeType
                    ? $charge->charge_type
                    : ChargeType::tryFrom((string) $charge->charge_type);

                if ($type === ChargeType::Refund) {
                    return false;
                }

                if ($type === ChargeType::Deposit && ! $invoiceDeposits) {
                    return false;
                }

                if ($type === ChargeType::Adjustment && bccomp((string) $charge->amount, '0', 2) < 0) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    public static function determineKind(Contact $contact): InvoiceKind
    {
        return $contact->fiscalComplete() ? InvoiceKind::Ordinary : InvoiceKind::Simplified;
    }

    /**
     * @param  Collection<int, Charge>  $charges
     */
    public static function sumGross(Collection $charges): string
    {
        $total = '0.00';
        foreach ($charges as $charge) {
            $total = bcadd($total, (string) $charge->amount, 2);
        }

        return $total;
    }

    public static function assertSimplifiedLimit(InvoiceKind $kind, string $grossTotal): void
    {
        if ($kind !== InvoiceKind::Simplified) {
            return;
        }

        $limit = (string) config('fiscal.simplified_gross_limit', '400.00');
        if (bccomp($grossTotal, $limit, 2) > 0) {
            throw ValidationException::withMessages([
                'invoice' => [__('errors.invoices.simplified_limit_exceeded', [
                    'limit' => $limit,
                    'gross' => $grossTotal,
                ])],
            ]);
        }
    }

    public static function resolveSite(Contract $contract): Site
    {
        $contract->loadMissing(['unitItem.item.site.country', 'unitItem.item.site.legalEntity']);

        $unit = $contract->unitItem?->item;
        if (! $unit instanceof Unit || $unit->site === null) {
            // Fall back to any unit item including closed ones (vacate closes effective_to).
            $item = $contract->items()
                ->where('item_type', 'unit')
                ->orderByDesc('id')
                ->first();
            $item?->loadMissing('item.site.country', 'item.site.legalEntity');
            $unit = $item?->item;
        }

        if (! $unit instanceof Unit || $unit->site === null) {
            throw ValidationException::withMessages([
                'contract' => [__('errors.invoices.missing_site')],
            ]);
        }

        return $unit->site;
    }

    public static function resolveLegalEntity(Site $site): LegalEntity
    {
        $site->loadMissing('legalEntity');
        $entity = $site->legalEntity;

        if ($entity === null) {
            throw ValidationException::withMessages([
                'legal_entity' => [__('errors.invoices.missing_legal_entity')],
            ]);
        }

        return $entity;
    }

    public static function defaultSeries(LegalEntity $entity, InvoiceKind $kind): InvoiceSeries
    {
        $series = InvoiceSeries::query()
            ->where('legal_entity_id', $entity->id)
            ->where('kind', $kind->value)
            ->where('is_default', true)
            ->whereNull('archived_at')
            ->first();

        if ($series === null) {
            throw ValidationException::withMessages([
                'invoice_series' => [__('errors.invoices.missing_default_series', [
                    'kind' => $kind->value,
                ])],
            ]);
        }

        return $series;
    }

    public static function localeForSite(Site $site): string
    {
        $site->loadMissing('country');
        $code = strtoupper((string) ($site->country?->code ?? ''));

        return match ($code) {
            'ES' => 'es',
            'FR' => 'fr',
            default => 'en',
        };
    }

    /** @return array{line1: string, line2: string|null, city: string, postal: string, country: string} */
    public static function issuerAddress(LegalEntity $entity): array
    {
        return [
            'line1' => $entity->address_line1,
            'line2' => $entity->address_line2,
            'city' => $entity->city,
            'postal' => $entity->postal_code,
            'country' => $entity->country_code,
        ];
    }

    /** @return array{line1: string|null, line2: string|null, city: string|null, postal: string|null, country: string|null} */
    public static function buyerAddress(Contact $contact): array
    {
        return [
            'line1' => $contact->billing_address_line1,
            'line2' => $contact->billing_address_line2,
            'city' => $contact->billing_city,
            'postal' => $contact->billing_postal_code,
            'country' => $contact->billing_country_code,
        ];
    }

    public static function buyerName(Contact $contact): string
    {
        if (filled($contact->billing_name)) {
            return (string) $contact->billing_name;
        }

        return trim((string) $contact->first_name.' '.(string) $contact->last_name);
    }

    private static function lineDescription(Charge $charge, Contract $contract, string $locale): string
    {
        $type = $charge->charge_type instanceof ChargeType
            ? $charge->charge_type
            : ChargeType::tryFrom((string) $charge->charge_type);

        $unitNumber = null;
        $contract->loadMissing(['unitItem.item']);
        $unit = $contract->unitItem?->item;
        if ($unit instanceof Unit) {
            $unitNumber = $unit->unit_number;
        }

        $start = $charge->period_start?->format('d M Y');
        $end = $charge->period_end?->format('d M Y');
        $period = ($start !== null && $end !== null) ? "{$start} – {$end}" : null;

        $key = match ($type) {
            ChargeType::Rent => 'invoices.lines.rent',
            ChargeType::Insurance => 'invoices.lines.insurance',
            ChargeType::Deposit => 'invoices.lines.deposit',
            ChargeType::LateFee => 'invoices.lines.late_fee',
            ChargeType::LienFee => 'invoices.lines.lien_fee',
            default => 'invoices.lines.other',
        };

        $translated = trans($key, [
            'unit' => $unitNumber ?? '',
            'period' => $period ?? '',
            'description' => (string) ($charge->description ?? ''),
        ], $locale);

        if ($translated === $key && filled($charge->description)) {
            return (string) $charge->description;
        }

        return $translated;
    }
}
