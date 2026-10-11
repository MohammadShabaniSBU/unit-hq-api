<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdConversionEvent;
use App\Models\Contact;
use App\Support\Advertising\ConversionEventKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AdConversionEvent>
 */
class AdConversionEventFactory extends Factory
{
    protected $model = AdConversionEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_key' => ConversionEventKey::Lead,
            'dedupe_id' => 'deal:'.Str::lower(Str::random(8)).':lead',
            'contact_id' => Contact::factory(),
            'subject_type' => 'deal',
            'subject_id' => 1,
            'site_id' => null,
            'value' => null,
            'currency' => null,
            'occurred_at' => now(),
        ];
    }
}
