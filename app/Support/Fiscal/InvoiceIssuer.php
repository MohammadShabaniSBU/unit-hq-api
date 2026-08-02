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
use App\Support\Communications\SiteLocale;
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

    public const REASON_VACATE_SETTLEMENT = 'vacate_settlement';

    public const REASON_TRANSFER_CREDIT = 'transfer_credit';

    public const REASON_OPERATOR_CORRECTION = 'operator_correction';

    /**
     * Issue a rectificative invoice correcting an already-issued original.
     * Signs on credit charges are preserved (totals are negative).
     *
     * @param  Collection<int, Charge>  $creditCharges
     */
    public static function issueRectificative(
        Invoice $original,
        Collection $creditCharges,
        string $reason,
        ?int $createdBy = null,
        ?InvoiceSeries $series = null,
    ): Invoice {
        $original->loadMissing(['contract.contact', 'contract.unitItem.item.site.country', 'contract.unitItem.item.site.legalEntity']);

        if ($original->status !== InvoiceStatus::Issued) {
            throw ValidationException::withMessages([
                'invoice' => [__('errors.invoices.rectify_original_not_issued')],
            ]);
        }

        if (! in_array($reason, [
            self::REASON_VACATE_SETTLEMENT,
            self::REASON_TRANSFER_CREDIT,
            self::REASON_OPERATOR_CORRECTION,
        ], true)) {
            throw ValidationException::withMessages([
                'reason' => [__('errors.invoices.rectify_invalid_reason')],
            ]);
        }

        $contract = $original->contract;
        if ($contract === null) {
            throw ValidationException::withMessages([
                'invoice' => [__('errors.invoices.rectify_missing_contract')],
            ]);
        }

        $eligible = self::filterCreditCharges($contract, $creditCharges, $original);
        if ($eligible->isEmpty()) {
            throw ValidationException::withMessages([
                'charge_ids' => [__('errors.invoices.rectify_no_eligible_credits')],
            ]);
        }

        $site = self::resolveSite($contract);
        $entity = self::resolveLegalEntity($site);
        $contact = $contract->contact;
        if ($contact === null) {
            throw ValidationException::withMessages([
                'contact' => [__('errors.invoices.missing_contact')],
            ]);
        }

        $kind = InvoiceKind::Rectificative;
        $series ??= self::defaultSeries($entity, $kind);
        InvoiceNumbering::assertKind($series, $kind->value);

        $number = InvoiceNumbering::allocate($series);
        $fullNumber = sprintf('%s-%06d', $series->code, $number);
        $locale = self::localeForSite($site);
        $issueDate = SiteClock::today($site)->toDateString();

        $netTotal = '0.00';
        $taxTotal = '0.00';
        $grossTotal = '0.00';
        foreach ($eligible as $charge) {
            $netTotal = bcadd($netTotal, (string) ($charge->net_amount ?? '0.00'), 2);
            $taxTotal = bcadd($taxTotal, (string) $charge->tax_amount, 2);
            $grossTotal = bcadd($grossTotal, (string) $charge->amount, 2);
        }

        $assertGross = bcadd($netTotal, $taxTotal, 2);
        if (bccomp($assertGross, $grossTotal, 2) !== 0) {
            throw new RuntimeException('Invoice totals do not match charge snapshots.');
        }

        $buyerStyleOrdinary = $original->kind === InvoiceKind::Ordinary
            || ($original->kind === InvoiceKind::Rectificative && filled($original->buyer_tax_id));

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
            'rectifies_invoice_id' => $original->id,
            'rectification_reason' => $reason,
            'issuer_name' => $entity->legal_name,
            'issuer_tax_id' => $entity->tax_id,
            'issuer_address' => self::issuerAddress($entity),
            'buyer_name' => self::buyerName($contact),
            'buyer_tax_id' => $buyerStyleOrdinary ? $contact->tax_id : null,
            'buyer_address' => $buyerStyleOrdinary ? self::buyerAddress($contact) : null,
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

        $activity = [
            'full_number' => $fullNumber,
            'original_full_number' => $original->full_number,
            'rectificative_full_number' => $fullNumber,
            'reason' => $reason,
            'contract_id' => $contract->id,
            'contact_id' => $contact->id,
            'net_total' => $netTotal,
            'tax_total' => $taxTotal,
            'gross_total' => $grossTotal,
            'currency' => (string) $contract->currency,
            'charge_ids' => $eligible->pluck('id')->values()->all(),
        ];

        RecordsActivity::core('invoice.rectified', $invoice, $activity);
        RecordsActivity::core('invoice.rectified', $original, $activity);

        return $invoice->load('lines');
    }

    /**
     * Group uninvoiced credit charges by the original invoice they correct and
     * issue one rectificative per group. Credits that cannot be matched to an
     * issued original are returned (never invents negative ordinary invoices).
     *
     * @param  Collection<int, Charge>  $credits
     * @return array{issued: Collection<int, Invoice>, unmatched: Collection<int, Charge>}
     */
    public static function issueCreditsForContract(
        Contract $contract,
        Collection $credits,
        string $reason,
        ?int $createdBy = null,
    ): array {
        $creditIds = $credits
            ->filter(fn (Charge $c) => self::isUninvoicedNegativeAdjustment($c, $contract))
            ->pluck('id')
            ->all();

        $credits = Charge::query()
            ->with('reversalOf')
            ->whereIn('id', $creditIds)
            ->get();

        $grouped = [];
        $unmatched = collect();

        foreach ($credits as $credit) {
            $originalInvoiceId = self::resolveOriginalInvoiceId($credit);
            if ($originalInvoiceId === null) {
                $unmatched->push($credit);

                continue;
            }
            $grouped[$originalInvoiceId][] = $credit;
        }

        $issued = collect();
        foreach ($grouped as $originalId => $group) {
            $original = Invoice::query()->find($originalId);
            if ($original === null || $original->status !== InvoiceStatus::Issued) {
                foreach ($group as $credit) {
                    $unmatched->push($credit);
                }

                continue;
            }

            $issued->push(self::issueRectificative(
                $original,
                collect($group),
                $reason,
                $createdBy,
            ));
        }

        return [
            'issued' => $issued->values(),
            'unmatched' => $unmatched->values(),
        ];
    }

    /**
     * Resolve the issued invoice id that a credit charge corrects.
     */
    public static function resolveOriginalInvoiceId(Charge $credit): ?int
    {
        $credit->loadMissing('reversalOf');

        if ($credit->reversal_of_charge_id !== null) {
            $original = $credit->reversalOf;
            if ($original !== null && $original->invoice_id !== null) {
                return (int) $original->invoice_id;
            }
        }

        $adjustedId = self::parseAdjustedChargeIdFromDescription((string) ($credit->description ?? ''));
        if ($adjustedId === null) {
            return null;
        }

        $original = Charge::query()->find($adjustedId);
        if ($original === null || $original->invoice_id === null) {
            return null;
        }

        return (int) $original->invoice_id;
    }

    public static function parseAdjustedChargeIdFromDescription(string $description): ?int
    {
        if (preg_match('/#(\d+)\s*$/', $description, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    public static function inferRectificationReason(Charge $credit): string
    {
        $description = (string) ($credit->description ?? '');

        if (str_starts_with($description, 'vacate.credit')) {
            return self::REASON_VACATE_SETTLEMENT;
        }
        if (str_starts_with($description, 'transfer.credit')) {
            return self::REASON_TRANSFER_CREDIT;
        }

        return self::REASON_OPERATOR_CORRECTION;
    }

    /**
     * Build a read-only preview of invoices that would be issued for settlement lines.
     *
     * @param  Collection<int, array<string, mixed>>|array<int, array<string, mixed>>  $creditLines  each with adjusts_charge_id, net, tax, gross
     * @param  Collection<int, array<string, mixed>>|array<int, array<string, mixed>>  $debitLines   each with net, tax, gross (positive)
     * @return list<array{kind: string, rectifies_full_number: string|null, gross_total: string, net_total: string, tax_total: string}>
     */
    public static function previewInvoicesToIssue(iterable $creditLines, iterable $debitLines = []): array
    {
        $byOriginal = [];
        foreach ($creditLines as $line) {
            $adjustsId = $line['adjusts_charge_id'] ?? null;
            if ($adjustsId === null) {
                continue;
            }
            $originalCharge = Charge::query()->find((int) $adjustsId);
            if ($originalCharge === null || $originalCharge->invoice_id === null) {
                continue;
            }
            $invoiceId = (int) $originalCharge->invoice_id;
            if (! isset($byOriginal[$invoiceId])) {
                $invoice = Invoice::query()->find($invoiceId);
                $byOriginal[$invoiceId] = [
                    'kind' => InvoiceKind::Rectificative->value,
                    'rectifies_full_number' => $invoice?->full_number,
                    'gross_total' => '0.00',
                    'net_total' => '0.00',
                    'tax_total' => '0.00',
                ];
            }
            $byOriginal[$invoiceId]['gross_total'] = bcadd(
                $byOriginal[$invoiceId]['gross_total'],
                (string) ($line['gross'] ?? '0.00'),
                2
            );
            $byOriginal[$invoiceId]['net_total'] = bcadd(
                $byOriginal[$invoiceId]['net_total'],
                (string) ($line['net'] ?? '0.00'),
                2
            );
            $byOriginal[$invoiceId]['tax_total'] = bcadd(
                $byOriginal[$invoiceId]['tax_total'],
                (string) ($line['tax'] ?? '0.00'),
                2
            );
        }

        $result = array_values($byOriginal);

        $debitGross = '0.00';
        $debitNet = '0.00';
        $debitTax = '0.00';
        $hasDebit = false;
        foreach ($debitLines as $line) {
            $gross = (string) ($line['gross'] ?? '0.00');
            if (bccomp($gross, '0', 2) <= 0) {
                continue;
            }
            $hasDebit = true;
            $debitGross = bcadd($debitGross, $gross, 2);
            $debitNet = bcadd($debitNet, (string) ($line['net'] ?? '0.00'), 2);
            $debitTax = bcadd($debitTax, (string) ($line['tax'] ?? '0.00'), 2);
        }

        if ($hasDebit) {
            $result[] = [
                'kind' => 'ordinary_or_simplified',
                'rectifies_full_number' => null,
                'gross_total' => $debitGross,
                'net_total' => $debitNet,
                'tax_total' => $debitTax,
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int, Charge>  $charges
     * @return Collection<int, Charge>
     */
    public static function filterCreditCharges(
        Contract $contract,
        Collection $charges,
        ?Invoice $original = null,
    ): Collection {
        $originalChargeIds = null;
        if ($original !== null) {
            $originalChargeIds = Charge::query()
                ->where('invoice_id', $original->id)
                ->pluck('id')
                ->all();
        }

        return $charges
            ->filter(function (Charge $charge) use ($contract, $original, $originalChargeIds): bool {
                if (! self::isUninvoicedNegativeAdjustment($charge, $contract)) {
                    return false;
                }

                if ((string) $charge->currency !== (string) $contract->currency) {
                    return false;
                }

                if ($original !== null) {
                    if ((string) $original->currency !== (string) $charge->currency) {
                        return false;
                    }

                    $resolved = self::resolveOriginalInvoiceId($charge);
                    if ($resolved !== null && $resolved !== (int) $original->id) {
                        return false;
                    }

                    // When resolution fails but charge_ids were explicitly picked for this
                    // original, still allow if reversal points at a charge on the original.
                    if ($resolved === null && $originalChargeIds !== null) {
                        $adjustedId = $charge->reversal_of_charge_id
                            ?? self::parseAdjustedChargeIdFromDescription((string) ($charge->description ?? ''));
                        if ($adjustedId === null || ! in_array((int) $adjustedId, $originalChargeIds, true)) {
                            return false;
                        }
                    }
                }

                return true;
            })
            ->values();
    }

    public static function isUninvoicedNegativeAdjustment(Charge $charge, Contract $contract): bool
    {
        if ((int) $charge->contract_id !== (int) $contract->id) {
            return false;
        }
        if ($charge->invoice_id !== null) {
            return false;
        }

        $type = $charge->charge_type instanceof ChargeType
            ? $charge->charge_type
            : ChargeType::tryFrom((string) $charge->charge_type);

        if ($type !== ChargeType::Adjustment) {
            return false;
        }

        return bccomp((string) $charge->amount, '0', 2) < 0;
    }

    /**
     * Split newly written settlement charges into credits (rectificative) and
     * ordinary-issuable debits.
     *
     * @param  Collection<int, Charge>  $charges
     * @return array{credits: Collection<int, Charge>, debits: Collection<int, Charge>}
     */
    public static function splitSettlementCharges(Contract $contract, Collection $charges): array
    {
        $credits = $charges
            ->filter(fn (Charge $c) => self::isUninvoicedNegativeAdjustment($c, $contract))
            ->values();

        $debits = self::filterCharges(
            $contract,
            $charges->reject(fn (Charge $c) => self::isUninvoicedNegativeAdjustment($c, $contract))->values()
        );

        return [
            'credits' => $credits,
            'debits' => $debits,
        ];
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
        $invoiceLateFees = (bool) config('fiscal.invoice_late_fees', false);

        return $charges
            ->filter(function (Charge $charge) use ($contract, $invoiceDeposits, $invoiceLateFees): bool {
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

                if ($type === ChargeType::LateFee && ! $invoiceLateFees) {
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
        return SiteLocale::for($site);
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
