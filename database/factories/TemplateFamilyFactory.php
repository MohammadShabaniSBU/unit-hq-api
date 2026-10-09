<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Enums\TemplateVersionStatus;
use App\Models\TemplateFamily;
use App\Models\TemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<TemplateFamily>
 */
class TemplateFamilyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => TemplateChannel::Email,
            'name' => fake()->words(3, true),
            'purpose' => TemplatePurpose::General,
            'archived_at' => null,
        ];
    }

    /**
     * Family, published version 1, and its locale variants.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $variants
     */
    public static function published(array $attributes = [], array $variants = []): TemplateFamily
    {
        return DB::transaction(function () use ($attributes, $variants): TemplateFamily {
            $family = TemplateFamily::factory()->create($attributes);

            // Insert variants while the version is still a draft. A published
            // version rejects new rows (model guard and the Postgres trigger).
            $version = TemplateVersion::query()->create([
                'template_family_id' => $family->id,
                'version_number' => 1,
                'status' => TemplateVersionStatus::Draft,
            ]);

            foreach ($variants as $variant) {
                $version->variants()->create([
                    ...$variant,
                    'template_family_id' => $family->id,
                ]);
            }

            $version->update([
                'status' => TemplateVersionStatus::Published,
                'published_at' => now(),
            ]);

            return $family;
        });
    }
}
