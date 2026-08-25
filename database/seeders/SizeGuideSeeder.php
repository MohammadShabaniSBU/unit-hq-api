<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SizeGuideMetric;
use App\Models\SizeGuide;
use App\Support\Facility\SizeGuideResolver;
use Illuminate\Database\Seeder;

/**
 * Conservative company-default bands. Over-recommending size costs money;
 * under-recommending is a mis-sale. Err large. 20–24 standard boxes land in
 * 12–16 m², not the ungrounded 5–8 m² from trace-30.
 */
class SizeGuideSeeder extends Seeder
{
    public function run(): void
    {
        $disclaimer = SizeGuideResolver::DISCLAIMER;

        foreach ($this->bands() as $band) {
            SizeGuide::query()->firstOrCreate(
                [
                    'site_id' => null,
                    'unit_class_id' => null,
                    'metric' => $band['metric'],
                    'min_quantity' => $band['min_quantity'],
                    'max_quantity' => $band['max_quantity'],
                ],
                [
                    'min_size' => $band['min_size'],
                    'max_size' => $band['max_size'],
                    'notes' => $band['notes'].' '.$disclaimer,
                    'archived_at' => null,
                ],
            );
        }
    }

    /**
     * @return list<array{metric: SizeGuideMetric, min_quantity: int, max_quantity: int, min_size: string, max_size: string, notes: string}>
     */
    private function bands(): array
    {
        return [
            [
                'metric' => SizeGuideMetric::StandardBoxes,
                'min_quantity' => 1,
                'max_quantity' => 8,
                'min_size' => '5.00',
                'max_size' => '8.00',
                'notes' => 'Conservative company default for a handful of boxes.',
            ],
            [
                'metric' => SizeGuideMetric::StandardBoxes,
                'min_quantity' => 9,
                'max_quantity' => 16,
                'min_size' => '8.00',
                'max_size' => '12.00',
                'notes' => 'Conservative company default; err large on a 1-bed overflow.',
            ],
            [
                'metric' => SizeGuideMetric::StandardBoxes,
                'min_quantity' => 17,
                'max_quantity' => 28,
                'min_size' => '12.00',
                'max_size' => '16.00',
                'notes' => 'Conservative: 20–24 standard boxes need more than a 5–8 m² unit.',
            ],
            [
                'metric' => SizeGuideMetric::StandardBoxes,
                'min_quantity' => 29,
                'max_quantity' => 45,
                'min_size' => '16.00',
                'max_size' => '25.00',
                'notes' => 'Conservative company default for a 2-bed house contents.',
            ],
            [
                'metric' => SizeGuideMetric::StandardBoxes,
                'min_quantity' => 46,
                'max_quantity' => 80,
                'min_size' => '25.00',
                'max_size' => '40.00',
                'notes' => 'Conservative company default for a 3-bed house contents.',
            ],
            [
                'metric' => SizeGuideMetric::RoomEquivalent,
                'min_quantity' => 1,
                'max_quantity' => 1,
                'min_size' => '5.00',
                'max_size' => '10.00',
                'notes' => 'Conservative: one room or studio overflow.',
            ],
            [
                'metric' => SizeGuideMetric::RoomEquivalent,
                'min_quantity' => 2,
                'max_quantity' => 2,
                'min_size' => '10.00',
                'max_size' => '16.00',
                'notes' => 'Conservative: a 1-bedroom equivalent.',
            ],
            [
                'metric' => SizeGuideMetric::RoomEquivalent,
                'min_quantity' => 3,
                'max_quantity' => 3,
                'min_size' => '16.00',
                'max_size' => '25.00',
                'notes' => 'Conservative: a 2-bedroom equivalent.',
            ],
            [
                'metric' => SizeGuideMetric::RoomEquivalent,
                'min_quantity' => 4,
                'max_quantity' => 4,
                'min_size' => '25.00',
                'max_size' => '40.00',
                'notes' => 'Conservative: a 3-bedroom equivalent.',
            ],
            [
                'metric' => SizeGuideMetric::Vehicle,
                'min_quantity' => 1,
                'max_quantity' => 1,
                'min_size' => '15.00',
                'max_size' => '20.00',
                'notes' => 'Conservative default for one car. Motorcycles may use a smaller class; vans need more. Confirm the vehicle.',
            ],
        ];
    }
}
