<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccessPointType;
use App\Models\AccessPoint;
use App\Models\AccessProviderAccount;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessPoint>
 */
class AccessPointFactory extends Factory
{
    protected $model = AccessPoint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'access_provider_account_id' => AccessProviderAccount::factory(),
            'site_id' => Site::factory(),
            'unit_id' => null,
            'point_type' => AccessPointType::Gate,
            'provider_point_id' => fake()->unique()->bothify('point-????-####'),
            'label' => fake()->words(2, true),
            'archived_at' => null,
        ];
    }

    public function gate(): static
    {
        return $this->state(fn (): array => [
            'point_type' => AccessPointType::Gate,
            'unit_id' => null,
            'label' => 'Main gate',
        ]);
    }

    public function zone(): static
    {
        return $this->state(fn (): array => [
            'point_type' => AccessPointType::Zone,
            'unit_id' => null,
            'label' => 'Zone A',
        ]);
    }

    public function unitDoor(int $unitId): static
    {
        return $this->state(fn (): array => [
            'point_type' => AccessPointType::UnitDoor,
            'unit_id' => $unitId,
            'label' => 'Unit door',
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'archived_at' => now(),
        ]);
    }
}
