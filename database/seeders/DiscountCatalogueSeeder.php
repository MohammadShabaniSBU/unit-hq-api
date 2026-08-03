<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DiscountKind;
use App\Models\Discount;
use App\Models\Employee;
use Illuminate\Database\Seeder;

/**
 * DISC-00 catalogue seeds: fixed percent menu + long-stay free_time promo.
 */
class DiscountCatalogueSeeder extends Seeder
{
    public function run(?Employee $createdBy = null): void
    {
        $createdById = $createdBy?->id ?? Employee::query()->value('id');

        Discount::query()->firstOrCreate(
            ['name' => '10% off', 'kind' => DiscountKind::Percent],
            [
                'params' => ['percent' => '10.00'],
                'applies_to' => 'unit',
                'tracks_rate_changes' => true,
                'created_by' => $createdById,
            ],
        );

        Discount::query()->firstOrCreate(
            ['name' => '20% off', 'kind' => DiscountKind::Percent],
            [
                'params' => ['percent' => '20.00'],
                'applies_to' => 'unit',
                'tracks_rate_changes' => true,
                'created_by' => $createdById,
            ],
        );

        Discount::query()->firstOrCreate(
            ['name' => 'Long-stay promo', 'kind' => DiscountKind::FreeTime],
            [
                'params' => [
                    'tiers' => [
                        ['min_commitment_weeks' => 4, 'free_weeks' => 2],
                        ['min_commitment_weeks' => 8, 'free_weeks' => 4],
                        ['min_commitment_weeks' => 12, 'free_weeks' => 6],
                    ],
                ],
                'applies_to' => 'unit',
                'tracks_rate_changes' => false,
                'created_by' => $createdById,
            ],
        );
    }
}
