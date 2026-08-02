<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccessSuspensionReason;
use App\Models\AccessSuspension;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessSuspension>
 */
class AccessSuspensionFactory extends Factory
{
    protected $model = AccessSuspension::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'reason' => AccessSuspensionReason::Manual,
            'delinquency_id' => null,
            'created_by' => null,
            'lifted_at' => null,
            'lifted_by' => null,
            'lift_reason' => null,
            'created_at' => now(),
        ];
    }

    public function delinquency(?int $delinquencyId = null): static
    {
        return $this->state(fn (): array => [
            'reason' => AccessSuspensionReason::Delinquency,
            'delinquency_id' => $delinquencyId,
        ]);
    }

    public function lifted(): static
    {
        return $this->state(fn (): array => [
            'lifted_at' => now(),
            'lift_reason' => 'manual',
        ]);
    }
}
