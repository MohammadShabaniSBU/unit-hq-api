<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai\Summaries;

use App\Enums\ContactChannelType;
use App\Enums\DealStatus;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Interaction;
use App\Models\Note;
use App\Support\Ai\Summaries\ContactSummaryContext;
use App\Support\Ai\Summaries\SummaryContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTwoSiteRbacFixture;
use Tests\TestCase;

class ContactSummaryContextTest extends TestCase
{
    use CreatesTwoSiteRbacFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoSiteRbacFixture();
        config([
            'ai.summaries.caps' => [
                'interactions' => 2,
                'notes' => 1,
                'body_chars' => 10,
            ],
        ]);
    }

    #[Test]
    public function build_shape_includes_identity_and_channel_types_without_values(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'company' => 'Analytical Engines',
        ]);
        $contact->channels()->create([
            'type' => ContactChannelType::Email,
            'value' => 'ada@example.com',
            'is_primary' => true,
            'opted_in' => true,
        ]);
        Note::query()->create([
            'notable_type' => $contact->getMorphClass(),
            'notable_id' => $contact->id,
            'employee_id' => $this->owner->id,
            'content' => 'A note with more than ten characters.',
        ]);

        $context = new ContactSummaryContext($contact, $this->owner, config('ai.summaries.caps'));
        $payload = $context->build();

        $this->assertSame('contact', $payload['entity']);
        $this->assertSame('Ada Lovelace', $payload['identity']['name']);
        $this->assertSame(['email'], $payload['channel_types']);
        $this->assertArrayNotHasKey('email', $payload['identity']);
        $this->assertSame('A note wit…', $payload['notes'][0]['content']);
        $this->assertSame(64, strlen($context->digest()));
    }

    #[Test]
    public function caps_limit_interactions_and_notes(): void
    {
        $contact = Contact::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            Interaction::query()->create([
                'contact_id' => $contact->id,
                'channel' => 'email',
                'direction' => 'inbound',
                'occurred_at' => now()->subMinutes($i),
                'content' => "Interaction {$i}",
            ]);
            Note::query()->create([
                'notable_type' => $contact->getMorphClass(),
                'notable_id' => $contact->id,
                'employee_id' => $this->owner->id,
                'content' => "Note {$i}",
            ]);
        }

        $context = (new SummaryContextResolver)->resolve($contact, $this->owner);
        $payload = $context->build();

        $this->assertCount(2, $payload['interactions']);
        $this->assertCount(1, $payload['notes']);
    }

    #[Test]
    public function site_scoped_employee_omits_out_of_grant_deals(): void
    {
        $contact = Contact::factory()->create();
        Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $this->siteA->id,
            'status' => DealStatus::Qualified,
        ]);
        Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $this->siteB->id,
            'status' => DealStatus::Qualified,
        ]);

        $context = (new SummaryContextResolver)->resolve($contact, $this->agent);
        $payload = $context->build();

        $this->assertSame(1, $payload['deals']['total']);
        $this->assertSame(1, $payload['deals']['open']);
    }

    #[Test]
    public function empty_contact_is_empty(): void
    {
        $contact = Contact::factory()->create();
        $context = (new SummaryContextResolver)->resolve($contact, $this->owner);

        $this->assertTrue($context->isEmpty());
    }
}
