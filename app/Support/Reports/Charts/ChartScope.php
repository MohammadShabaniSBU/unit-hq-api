<?php

declare(strict_types=1);

namespace App\Support\Reports\Charts;

use App\Enums\ContractStatus;
use App\Support\Billing\BillingMath;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shared query fragments for chart reports. Plain query builder, no Eloquent casts.
 * Money stays bcmath until {@see ChartSeries}.
 */
final class ChartScope
{
    /** @var list<string> */
    public const EXCLUDED_CONTRACT_STATUSES = [
        ContractStatus::AwaitingSignature->value,
        ContractStatus::Cancelled->value,
    ];

    /**
     * Restrict $column (a contract id) to contracts that have a unit line in the site set.
     * null is company-wide. An empty list matches nothing.
     *
     * @param  list<int>|null  $siteIds
     */
    public static function whereContractInSites(Builder $query, string $column, ?array $siteIds): void
    {
        if ($siteIds === null) {
            return;
        }

        if ($siteIds === []) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->whereIn($column, function (Builder $sub) use ($siteIds): void {
            $sub->select('contract_items.contract_id')
                ->from('contract_items')
                ->join('units', 'units.id', '=', 'contract_items.item_id')
                ->where('contract_items.item_type', 'unit')
                ->whereIn('units.site_id', $siteIds);
        });
    }

    /**
     * Drop reversal pairs. Same rule as analytics.v_revenue.
     * The outer query must read the charges table (alias charges).
     */
    public static function withoutReversedCharges(Builder $query): void
    {
        $query->whereNull('charges.reversal_of_charge_id')
            ->whereNotExists(function (Builder $sub): void {
                $sub->selectRaw('1')
                    ->from('charges as rev')
                    ->whereColumn('rev.reversal_of_charge_id', 'charges.id');
            });
    }

    /**
     * Enabled units with site currency and class area.
     *
     * @param  list<int>|null  $siteIds
     */
    public static function units(?array $siteIds): Builder
    {
        $query = DB::table('units')
            ->join('sites', 'sites.id', '=', 'units.site_id')
            ->join('unit_classes', 'unit_classes.id', '=', 'units.unit_class_id')
            ->where('units.enabled', true)
            ->select([
                'units.id',
                'units.site_id',
                'sites.name as site_name',
                'sites.currency',
                'units.unit_class_id',
                'unit_classes.code as class_code',
                'unit_classes.size as area',
            ]);

        if ($siteIds === []) {
            $query->whereRaw('0 = 1');
        } elseif ($siteIds !== null) {
            $query->whereIn('units.site_id', $siteIds);
        }

        return $query;
    }

    public static function currency(mixed $raw): string
    {
        $value = strtoupper(trim((string) $raw));

        return $value !== '' ? $value : 'EUR';
    }

    /**
     * Percent to 1 decimal place. Null when the denominator is zero.
     */
    public static function pct(string|int|float $numerator, string|int|float $denominator): ?float
    {
        $num = self::asBc($numerator);
        $den = self::asBc($denominator);

        if (bccomp($den, '0', 8) === 0) {
            return null;
        }

        $scaled = bcdiv(bcmul($num, '100', 8), $den, 8);
        $bias = bccomp($scaled, '0', 8) >= 0 ? '0.05' : '-0.05';

        return (float) bcadd(bcadd($scaled, $bias, 8), '0', 1);
    }

    public static function decimal(string|int|float $value): string
    {
        return BillingMath::round2(self::asBc($value));
    }

    private static function asBc(string|int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return number_format($value, 8, '.', '');
        }

        return $value;
    }
}
