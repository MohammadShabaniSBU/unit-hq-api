<?php

declare(strict_types=1);

namespace App\Support\Facility;

use App\Models\Site;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Support\Billing\BillingMath;
use RuntimeException;

/**
 * Demo-world guard: current catalogue prices must be monotonic in class size,
 * and an XL variant must not undercut a same-size base.
 */
final class AssertsCatalogueMonotonicity
{
    public static function assert(): void
    {
        foreach (Site::query()->get() as $site) {
            /** @var list<array{size: string, amount: string, xl: bool, label: string}> $rows */
            $rows = [];

            $rates = UnitClassRate::query()
                ->where('site_id', $site->id)
                ->with(['unitClass', 'price'])
                ->get();

            foreach ($rates as $rate) {
                $class = $rate->unitClass;
                $price = $rate->price;
                if ($class === null || $price === null || $class->size === null) {
                    continue;
                }

                $rows[] = [
                    'size' => BillingMath::round2((string) $class->size),
                    'amount' => BillingMath::round2((string) $price->amount),
                    'xl' => self::isXl($class),
                    'label' => (string) $class->label,
                ];
            }

            $count = count($rows);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = $rows[$i];
                    $b = $rows[$j];
                    $sizeCmp = BillingMath::cmp($a['size'], $b['size'], 2);

                    if ($sizeCmp < 0 && BillingMath::cmp($a['amount'], $b['amount'], 2) > 0) {
                        throw new RuntimeException(
                            "Demo catalogue is not monotonic at site {$site->code}: {$a['label']} ({$a['amount']}) costs more than larger {$b['label']} ({$b['amount']}).",
                        );
                    }
                    if ($sizeCmp > 0 && BillingMath::cmp($b['amount'], $a['amount'], 2) > 0) {
                        throw new RuntimeException(
                            "Demo catalogue is not monotonic at site {$site->code}: {$b['label']} ({$b['amount']}) costs more than larger {$a['label']} ({$a['amount']}).",
                        );
                    }
                    if ($sizeCmp === 0 && $a['xl'] !== $b['xl']) {
                        $xl = $a['xl'] ? $a : $b;
                        $base = $a['xl'] ? $b : $a;
                        if (BillingMath::cmp($xl['amount'], $base['amount'], 2) < 0) {
                            throw new RuntimeException(
                                "Demo catalogue XL premium missing at site {$site->code}: {$xl['label']} ({$xl['amount']}) is cheaper than same-size {$base['label']} ({$base['amount']}).",
                            );
                        }
                    }
                }
            }
        }
    }

    private static function isXl(UnitClass $class): bool
    {
        return str_contains(strtoupper($class->label), 'XL');
    }
}
