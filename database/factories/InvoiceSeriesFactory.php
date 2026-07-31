<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InvoiceSeriesKind;
use App\Models\InvoiceSeries;
use App\Models\LegalEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceSeries>
 */
class InvoiceSeriesFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_entity_id' => LegalEntity::factory(),
            'code' => strtoupper(fake()->unique()->bothify('??####')),
            'kind' => InvoiceSeriesKind::Ordinary,
            'next_number' => 1,
            'is_default' => false,
            'archived_at' => null,
        ];
    }

    public function ordinary(): static
    {
        return $this->state(fn () => ['kind' => InvoiceSeriesKind::Ordinary]);
    }

    public function simplified(): static
    {
        return $this->state(fn () => ['kind' => InvoiceSeriesKind::Simplified]);
    }

    public function rectificative(): static
    {
        return $this->state(fn () => ['kind' => InvoiceSeriesKind::Rectificative]);
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
