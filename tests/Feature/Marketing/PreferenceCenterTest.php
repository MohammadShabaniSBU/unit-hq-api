<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Enums\TemplatePurpose;
use App\Models\ChannelSuppression;
use App\Models\ConsentEvent;
use App\Models\ConsentText;
use App\Models\Contact;
use App\Models\Site;
use App\Models\SubscriptionList;
use App\Support\Automation\SubjectTokenBag;
use App\Support\Communications\Channel;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\EmailSender;
use App\Support\Communications\SuppressionReason;
use App\Support\Communications\SuppressionScope;
use App\Support\Communications\UnsubscribeToken;
use App\Support\Marketing\ConsentSource;
use App\Support\Marketing\ConsentState;
use App\Support\Marketing\ConsentWriter;
use App\Support\Marketing\PreferenceToken;
use Database\Seeders\SubscriptionListSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class PreferenceCenterTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    private SubscriptionList $promotions;

    private SubscriptionList $newsletter;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seed(SubscriptionListSeeder::class);
        $this->promotions = SubscriptionList::query()->where('key', 'promotions')->firstOrFail();
        $this->newsletter = SubscriptionList::query()->where('key', 'newsletter')->firstOrFail();
    }

    #[Test]
    public function get_on_either_url_does_not_change_consent_or_suppression(): void
    {
        $contact = $this->grantedContact('es');
        $token = PreferenceToken::issue($contact->id, Channel::Email, 'ada@example.com', $this->newsletter->id, 42);
        $events = ConsentEvent::query()->count();
        $suppressions = ChannelSuppression::query()->count();

        $unsubscribe = $this->get('/api/comms/unsubscribe/'.$token);
        $unsubscribe->assertRedirect(PreferenceToken::pageUrl($token));

        $preferences = $this->getJson('/api/comms/preferences/'.$token);
        $preferences->assertOk();
        $preferences->assertJsonPath('data.contact_first_name', $contact->first_name);
        $preferences->assertJsonPath('data.locale', 'es');

        $labels = collect($preferences->json('data.lists'))->pluck('label', 'key');
        $this->assertSame('Boletín', $labels['newsletter']);
        $this->assertSame('Ofertas y promociones', $labels['promotions']);

        $this->assertSame($events, ConsentEvent::query()->count());
        $this->assertSame($suppressions, ChannelSuppression::query()->count());
        $this->assertTrue(ConsentState::isGranted($contact->id, Channel::Email, $this->newsletter->id));
        $this->assertTrue(ConsentState::isGranted($contact->id, Channel::Email, $this->promotions->id));
    }

    #[Test]
    public function one_click_post_withdraws_only_the_token_list(): void
    {
        $contact = $this->grantedContact('en');
        $token = PreferenceToken::issue($contact->id, Channel::Email, 'ada@example.com', $this->newsletter->id, 42);

        $response = $this->withHeader('User-Agent', 'Gmail One-Click')
            ->post('/api/comms/unsubscribe/'.$token, [
                'List-Unsubscribe' => 'One-Click',
            ]);

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());

        $this->assertFalse(ConsentState::isGranted($contact->id, Channel::Email, $this->newsletter->id));
        $this->assertTrue(ConsentState::isGranted($contact->id, Channel::Email, $this->promotions->id));
        $this->assertSame(0, ChannelSuppression::query()->count());

        $event = ConsentEvent::query()->where('source', ConsentSource::OneClick)->firstOrFail();
        $this->assertSame($this->newsletter->id, $event->subscription_list_id);
        $this->assertSame('Gmail One-Click', $event->evidence['user_agent'] ?? null);
        $this->assertSame(42, $event->evidence['message_id'] ?? null);
        $this->assertArrayHasKey('ip', $event->evidence ?? []);
    }

    #[Test]
    public function leaving_newsletter_keeps_promotions_and_can_be_restored(): void
    {
        $contact = $this->grantedContact('en');
        $token = PreferenceToken::issue($contact->id, Channel::Email, 'ada@example.com');

        $this->postJson('/api/comms/preferences/'.$token, [
            'changes' => [
                ['list_id' => $this->newsletter->id, 'channel' => 'email', 'subscribed' => false],
            ],
        ])->assertOk();

        $this->assertFalse(ConsentState::isGranted($contact->id, Channel::Email, $this->newsletter->id));
        $this->assertTrue(ConsentState::isGranted($contact->id, Channel::Email, $this->promotions->id));
        $this->assertSame(0, ChannelSuppression::query()->count());
        $this->assertSame(
            1,
            ConsentEvent::query()->where('source', ConsentSource::PreferenceCenter)->count(),
        );

        $this->postJson('/api/comms/preferences/'.$token, [
            'changes' => [
                ['list_id' => $this->newsletter->id, 'channel' => 'email', 'subscribed' => true],
            ],
        ])->assertOk();

        $this->assertTrue(ConsentState::isGranted($contact->id, Channel::Email, $this->newsletter->id));
        $this->assertTrue(ConsentState::isGranted($contact->id, Channel::Email, $this->promotions->id));
    }

    #[Test]
    public function legacy_unsubscribe_token_redirects_then_unsubscribes_all(): void
    {
        $contact = $this->grantedContact('en');
        $token = UnsubscribeToken::issue('ada@example.com');
        $events = ConsentEvent::query()->count();
        $suppressions = ChannelSuppression::query()->count();

        $this->get('/api/comms/unsubscribe/'.$token)
            ->assertRedirect(PreferenceToken::pageUrl($token));
        $this->getJson('/api/comms/preferences/'.$token)->assertOk();

        $this->assertSame($events, ConsentEvent::query()->count());
        $this->assertSame($suppressions, ChannelSuppression::query()->count());

        $this->postJson('/api/comms/preferences/'.$token, [
            'unsubscribe_all' => true,
        ])->assertOk();

        $this->assertFalse(ConsentState::isGranted($contact->id, Channel::Email, $this->newsletter->id));
        $this->assertFalse(ConsentState::isGranted($contact->id, Channel::Email, $this->promotions->id));

        $row = ChannelSuppression::query()->active()->where('address', 'ada@example.com')->firstOrFail();
        $this->assertSame(SuppressionScope::Marketing, $row->scope);
        $this->assertSame(SuppressionReason::Unsubscribed, $row->reason);
        $this->assertSame(
            2,
            ConsentEvent::query()->where('source', ConsentSource::PreferenceCenter)->count(),
        );
    }

    #[Test]
    public function tampered_token_is_not_found(): void
    {
        $contact = $this->grantedContact('en');
        $token = 'x'.PreferenceToken::issue($contact->id, Channel::Email, 'ada@example.com');

        $this->getJson('/api/comms/preferences/'.$token)->assertNotFound();
        $this->getJson('/api/comms/unsubscribe/'.$token)->assertNotFound();
        $this->post('/api/comms/unsubscribe/'.$token)->assertNotFound();
        $this->postJson('/api/comms/preferences/'.$token, [
            'unsubscribe_all' => true,
        ])->assertNotFound();

        $this->assertTrue(ConsentState::isGranted($contact->id, Channel::Email, $this->newsletter->id));
        $this->assertSame(0, ChannelSuppression::query()->count());
    }

    #[Test]
    public function marketing_list_unsubscribe_header_uses_a_preference_token(): void
    {
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $contact = Contact::factory()->create(['email' => 'ada@example.com', 'first_name' => 'Ada']);
        $this->givePrimaryEmail($contact, 'ada@example.com');

        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo-mkt'], 201),
        ]);

        app(EmailSender::class)->send(
            new EmailMessage(
                to: [new EmailAddress('ada@example.com')],
                subject: 'Newsletter',
                html: '<p>Hi</p>',
                text: 'Hi',
            ),
            $site,
            $contact,
            SendContext::manual(SendClass::Marketing, [
                'subscription_list_id' => $this->newsletter->id,
            ]),
        );

        Http::assertSent(function ($request) use ($contact): bool {
            $headers = $request->data()['headers'] ?? [];
            $header = (string) ($headers['List-Unsubscribe'] ?? '');
            $url = trim($header, '<>');
            $path = parse_url($url, PHP_URL_PATH);
            $token = is_string($path) ? basename($path) : '';
            $claims = PreferenceToken::parse($token);

            return $claims !== null
                && $claims->contactId === $contact->id
                && $claims->channel === Channel::Email
                && $claims->listId === $this->newsletter->id
                && ($headers['List-Unsubscribe-Post'] ?? null) === 'List-Unsubscribe=One-Click';
        });
    }

    #[Test]
    public function template_tokens_resolve_per_recipient_and_sample_includes_them(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'ada@example.com',
            'first_name' => 'Ada',
        ]);

        $bag = SubjectTokenBag::forContact($contact);
        $path = parse_url((string) $bag['unsubscribe_url'], PHP_URL_PATH);
        $token = is_string($path) ? basename($path) : '';
        $claims = PreferenceToken::parse($token);

        $this->assertNotNull($claims);
        $this->assertSame($contact->id, $claims->contactId);
        $this->assertStringContainsString('/preferences/'.$token, (string) $bag['preferences_url']);

        $sample = SubjectTokenBag::sample(TemplatePurpose::General);
        $this->assertArrayHasKey('preferences_url', $sample);
        $this->assertArrayHasKey('unsubscribe_url', $sample);
        $this->assertContains('preferences_url', SubjectTokenBag::vocabulary());
        $this->assertContains('unsubscribe_url', SubjectTokenBag::vocabulary());
    }

    #[Test]
    public function preference_routes_are_rate_limited(): void
    {
        foreach (['api/comms/preferences/{token}', 'api/comms/unsubscribe/{token}'] as $uri) {
            $route = collect(Route::getRoutes())->first(
                fn ($candidate): bool => $candidate->uri() === $uri,
            );
            $this->assertNotNull($route);

            $limited = collect($route->gatherMiddleware())
                ->contains(fn (string $middleware): bool => str_contains($middleware, '30,1'));
            $this->assertTrue($limited, $uri);
        }
    }

    private function grantedContact(string $locale): Contact
    {
        $text = ConsentText::factory()->create();
        $contact = Contact::factory()->create([
            'first_name' => 'Ada',
            'email' => 'ada@example.com',
            'locale' => $locale,
        ]);

        ConsentWriter::grant($contact, Channel::Email, $this->newsletter, ConsentSource::Form, $text->id);
        ConsentWriter::grant($contact, Channel::Email, $this->promotions, ConsentSource::Form, $text->id);

        return $contact;
    }
}
