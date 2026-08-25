<?php

declare(strict_types=1);

namespace App\Support\Facility;

use App\Enums\SizeGuideMetric;
use App\Models\SizeGuide;
use Illuminate\Support\Collection;

final class SizeGuideResolver
{
    public const DISCLAIMER = 'This is an estimate based on typical contents; ceiling height and access type change what actually fits. Measure before you commit.';

    /**
     * @return Collection<int, SizeGuide>
     */
    public function resolve(SizeGuideMetric $metric, ?int $quantity, ?int $siteId): Collection
    {
        $query = SizeGuide::query()
            ->active()
            ->where('metric', $metric)
            ->where(function ($inner) use ($siteId): void {
                $inner->whereNull('site_id');
                if ($siteId !== null) {
                    $inner->orWhere('site_id', $siteId);
                }
            });

        if ($quantity !== null) {
            $query
                ->where(function ($inner) use ($quantity): void {
                    $inner->whereNull('min_quantity')->orWhere('min_quantity', '<=', $quantity);
                })
                ->where(function ($inner) use ($quantity): void {
                    $inner->whereNull('max_quantity')->orWhere('max_quantity', '>=', $quantity);
                });
        }

        /** @var Collection<int, SizeGuide> $rows */
        $rows = $query->with(['site:id,name', 'unitClass:id,label,size'])->get();

        return $rows
            ->filter(function (SizeGuide $row) use ($rows, $quantity): bool {
                $spec = $row->specificity();
                foreach ($rows as $other) {
                    if ($other->id === $row->id) {
                        continue;
                    }
                    if ($other->specificity() > $spec && $this->competes($row, $other, $quantity)) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
    }

    private function competes(SizeGuide $a, SizeGuide $b, ?int $quantity): bool
    {
        if ($quantity !== null) {
            return true;
        }

        return $a->min_quantity === $b->min_quantity
            && $a->max_quantity === $b->max_quantity;
    }
}
