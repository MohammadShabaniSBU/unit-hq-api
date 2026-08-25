<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SiteServiceAreaKind;
use App\Models\Site;
use App\Models\SiteServiceArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteServiceArea>
 */
class SiteServiceAreaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'kind' => SiteServiceAreaKind::PostcodePrefix,
            'value' => '280',
            'archived_at' => null,
        ];
    }
}
