<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\ChannelSuppression;
use App\Models\Contact;
use App\Models\Site;
use App\Support\Communications\Channel;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\EmailSender;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\SuppressionReason;
use App\Support\Communications\SuppressionScope;
use App\Support\Communications\SuppressionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class SuppressionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    public function test_address_keyed_survives_redact_and_readd(): void
    {
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);

        $contact = Contact::factory()->create();
        $channel = $this->givePrimaryEmail($contact, 'typo@example.com');

        SuppressionWriter::write(
            Channel::Email,
            'typo@example.com',
            SuppressionScope::All,
            SuppressionReason::HardBounce,
        );

        $channel->delete();
        $this->givePrimaryEmail($contact, 'typo@example.com');

        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'should-not'], 201),
        ]);

        $blocked = app(EmailSender::class)->send(
            new EmailMessage(
                to: [new EmailAddress('typo@example.com')],
                subject: 'Retry',
                html: '<p>Hi</p>',
                text: 'Hi',
            ),
            $site,
            $contact,
            SendContext::manual(SendClass::Transactional),
        );

        $this->assertTrue($blocked->wasSuppressed());
        Http::assertNothingSent();

        $this->artisan('contacts:redact', ['contact' => $contact->id])->assertSuccessful();

        $this->assertTrue(
            ChannelSuppression::query()
                ->active()
                ->where('channel', Channel::Email)
                ->where('address', 'typo@example.com')
                ->exists(),
        );

        $redactionConfig = (string) file_get_contents(config_path('redaction.php'));
        $this->assertStringContainsString('channel_suppressions', $redactionConfig);
        $this->assertStringContainsString('intentionally NOT touched', $redactionConfig);

        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'still-should-not'], 201),
        ]);
        $stillBlocked = app(EmailSender::class)->send(
            new EmailMessage(
                to: [new EmailAddress('typo@example.com')],
                subject: 'After redact',
                html: '<p>Hi</p>',
                text: 'Hi',
            ),
            $site,
            $contact,
            SendContext::manual(SendClass::Transactional),
        );
        $this->assertTrue($stillBlocked->wasSuppressed());
        Http::assertNothingSent();
    }
}
