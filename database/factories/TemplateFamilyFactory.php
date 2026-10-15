<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Enums\TemplateVersionStatus;
use App\Models\TemplateFamily;
use App\Models\TemplateVersion;
use App\Support\Communications\EmailBlockDocument;
use Database\Support\EmailDocBuilder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<TemplateFamily>
 */
class TemplateFamilyFactory extends Factory
{
    private bool $legacyDocument = false;

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
        return TemplateFamily::factory()->publish($attributes, $variants);
    }

    /**
     * Published email variants without a document store v1, so upcast stays testable.
     */
    public function legacyV1(): static
    {
        $this->legacyDocument = true;

        return $this;
    }

    /**
     * Family, published version 1, and its locale variants.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $variants
     */
    public function publish(array $attributes = [], array $variants = []): TemplateFamily
    {
        $legacy = $this->legacyDocument;

        return DB::transaction(function () use ($attributes, $variants, $legacy): TemplateFamily {
            $family = $this->create($attributes);

            // Insert variants while the version is still a draft. A published
            // version rejects new rows (model guard and the Postgres trigger).
            $version = TemplateVersion::query()->create([
                'template_family_id' => $family->id,
                'version_number' => 1,
                'status' => TemplateVersionStatus::Draft,
            ]);

            foreach ($variants as $variant) {
                $version->variants()->create([
                    ...$this->fillDocument($family, $variant, $legacy),
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

    /**
     * @param  array<string, mixed>  $variant
     * @return array<string, mixed>
     */
    private function fillDocument(TemplateFamily $family, array $variant, bool $legacy): array
    {
        $channel = $family->channel instanceof TemplateChannel
            ? $family->channel
            : TemplateChannel::from((string) $family->channel);

        if ($channel !== TemplateChannel::Email) {
            return $variant;
        }

        if (isset($variant['blocks']) && is_array($variant['blocks'])) {
            return $variant;
        }

        $legacyHtml = $variant['legacy_html'] ?? null;
        if (is_string($legacyHtml) && $legacyHtml !== '') {
            return $variant;
        }

        $variant['blocks'] = $legacy
            ? self::legacyV1Document()
            : EmailDocBuilder::make((string) $family->name)
                ->section('100', fn ($column) => $column->paragraph('<p>Hello</p>'))
                ->toArray();
        $variant['legacy_html'] = null;

        return $variant;
    }

    /**
     * @return array<string, mixed>
     */
    private static function legacyV1Document(): array
    {
        return EmailBlockDocument::validate([
            'version' => 1,
            'blocks' => [[
                'id' => 'p1',
                'type' => 'paragraph',
                'params' => ['html' => '<p>Hello</p>'],
            ]],
        ]);
    }
}
