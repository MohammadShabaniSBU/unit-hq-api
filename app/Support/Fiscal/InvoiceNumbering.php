<?php

declare(strict_types=1);

namespace App\Support\Fiscal;

use App\Enums\InvoiceSeriesKind;
use App\Models\InvoiceSeries;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Gapless invoice number allocation.
 *
 * {@see allocate()} MUST be called inside the caller's DB transaction.
 * The row lock serialises concurrent issuers; the increment only survives
 * if the surrounding transaction commits. Never allocate outside an issue
 * transaction, never reserve numbers, never fill gaps by hand.
 */
final class InvoiceNumbering
{
    /**
     * Claim the next number for the series under a row lock.
     */
    public static function allocate(InvoiceSeries $series): int
    {
        $row = DB::table('invoice_series')
            ->where('id', $series->id)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new RuntimeException("Invoice series {$series->id} not found.");
        }

        DB::table('invoice_series')
            ->where('id', $series->id)
            ->increment('next_number');

        return (int) $row->next_number;
    }

    /**
     * Guard that the series kind matches the invoice being issued.
     * Used by S03-03 / S03-04 issuance paths.
     *
     * @throws ValidationException
     */
    public static function assertKind(InvoiceSeries $series, string $kind): void
    {
        $expected = $series->kind instanceof InvoiceSeriesKind
            ? $series->kind->value
            : (string) $series->kind;

        if ($expected !== $kind) {
            throw ValidationException::withMessages([
                'invoice_series' => [__('errors.invoice_series.kind_mismatch', [
                    'expected' => $expected,
                    'given' => $kind,
                ])],
            ]);
        }
    }
}
