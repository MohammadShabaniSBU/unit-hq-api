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

        Discount::query()->updateOrCreate(
            ['name' => '10% off', 'kind' => DiscountKind::Percent],
            [
                'params' => ['percent' => '10.00'],
                'applies_to' => 'unit',
                'tracks_rate_changes' => true,
                'agent_offerable' => false,
                'customer_terms' => null,
                'created_by' => $createdById,
            ],
        );

        Discount::query()->updateOrCreate(
            ['name' => '20% off', 'kind' => DiscountKind::Percent],
            [
                'params' => ['percent' => '20.00'],
                'applies_to' => 'unit',
                'tracks_rate_changes' => true,
                'agent_offerable' => false,
                'customer_terms' => null,
                'created_by' => $createdById,
            ],
        );

        Discount::query()->updateOrCreate(
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
                'agent_offerable' => true,
                'customer_terms' => [
                    'en' => 'Commit to 4 weeks or more and your first 2 weeks are free.',
                    'es' => 'Comprométete a 4 semanas o más y las 2 primeras semanas son gratis.',
                    'fr' => 'Engagez-vous pour 4 semaines ou plus et vos 2 premières semaines sont offertes.',
                ],
                'created_by' => $createdById,
            ],
        );
    }
}
