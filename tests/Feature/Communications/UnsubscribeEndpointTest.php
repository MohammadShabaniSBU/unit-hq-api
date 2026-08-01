<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\ChannelSuppression;
use App\Models\Contact;
use App\Models\Site;
use App\Support\Communications\Channel;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\EmailSender;
use App\Support\Communications\SuppressionReason;
use App\Support\Communications\SuppressionScope;
use App\Support\Communications\UnsubscribeToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class UnsubscribeEndpointTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    public function test_token_write_idempotent(): void
    {
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $contact = Contact::factory()->create();
        $this->givePrimaryEmail($contact, 'bulk@example.com');

        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::sequence()
                ->push(['messageId' => 'brevo-mkt'], 201)
                ->push(['messageId' => 'brevo-txn'], 201),
        ]);

        app(EmailSender::class)->send(
            new EmailMessage(
                to: [new EmailAddress('bulk@example.com')],
                subject: 'Campaign',
                html: '<p>Hi</p>',
                text: 'Hi',
            ),
            $site,
            $contact,
            SendContext::manual(SendClass::Marketing),
        );

        Http::assertSent(function ($request) {
            $headers = $request->data()['headers'] ?? [];

            return isset($headers['List-Unsubscribe'], $headers['List-Unsubscribe-Post'])
                && str_contains((string) $headers['List-Unsubscribe'], '/api/comms/unsubscribe/')
                && $headers['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click';
        });

        app(EmailSender::class)->send(
            new EmailMessage(
                to: [new EmailAddress('bulk@example.com')],
                subject: 'Invoice',
                html: '<p>Pay</p>',
                text: 'Pay',
            ),
            $site,
            $contact,
            SendContext::manual(SendClass::Transactional),
        );
        Http::assertSent(function ($request) {
            $data = $request->data();
            $headers = $data['headers'] ?? [];

            return ($data['subject'] ?? null) === 'Invoice'
                && ! isset($headers['List-Unsubscribe']);
        });

        $token = UnsubscribeToken::issue('bulk@example.com');

        $this->post("/api/comms/unsubscribe/{$token}")
            ->assertOk();

        $row = ChannelSuppression::query()
            ->active()
            ->where('address', 'bulk@example.com')
            ->firstOrFail();
        $this->assertSame(Channel::Email, $row->channel);
        $this->assertSame(SuppressionScope::Marketing, $row->scope);
        $this->assertSame(SuppressionReason::Unsubscribed, $row->reason);

        $this->post("/api/comms/unsubscribe/{$token}")
            ->assertOk();

        $this->assertSame(
            1,
            ChannelSuppression::query()
                ->active()
                ->where('address', 'bulk@example.com')
                ->count(),
        );

        $this->post('/api/comms/unsubscribe/not-a-valid-token')
            ->assertNotFound();
    }
}
