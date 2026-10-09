<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConsentEvent;
use App\Models\ConsentText;
use App\Models\Contact;
use App\Models\SubscriptionList;
use App\Support\Communications\Channel;
use App\Support\Marketing\ConsentAction;
use App\Support\Marketing\ConsentSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsentEvent>
 */
class ConsentEventFactory extends Factory
{
    protected $model = ConsentEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'channel' => Channel::Email,
            'subscription_list_id' => SubscriptionList::factory(),
            'action' => ConsentAction::Granted,
            'source' => ConsentSource::Form,
            'consent_text_id' => ConsentText::factory(),
            'evidence' => null,
            'causer_type' => null,
            'causer_id' => null,
            'occurred_at' => now(),
        ];
    }
}
