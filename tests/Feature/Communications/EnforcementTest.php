<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\ChannelSuppression;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Site;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\EmailSender;
use App\Support\Communications\SuppressionReason;
use App\Support\Communications\SuppressionScope;
use App\Support\Communications\SuppressionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class EnforcementTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    public function test_class_scope_matrix(): void
    {
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $contact = Contact::factory()->create();
        $this->givePrimaryEmail($contact, 'renter@example.com');

        $seq = 0;
        Http::fake([
            'api.brevo.com/v3/smtp/email' => function () use (&$seq) {
                $seq++;

                return Http::response(['messageId' => 'brevo-'.$seq], 201);
            },
        ]);

        // scope=all blocks transactional
        SuppressionWriter::write(
            Channel::Email,
            'renter@example.com',
            SuppressionScope::All,
            SuppressionReason::HardBounce,
        );
        $blockedTxn = app(EmailSender::class)->send(
            $this->email('renter@example.com'),
            $site,
            $contact,
            SendContext::manual(SendClass::Transactional),
        );
        $this->assertTrue($blockedTxn->wasSuppressed());
        $this->assertSame('hard_bounce', $blockedTxn->suppressedReason);
        $this->assertSame(0, $seq);
        $failed = Message::query()->findOrFail($blockedTxn->messageId);
        $this->assertSame(MessageStatus::Failed, $failed->status);
        $this->assertSame('hard_bounce', $failed->detail['suppressed_reason'] ?? null);

        // scope=all blocks marketing
        $blockedMkt = app(EmailSender::class)->send(
            $this->email('renter@example.com', 'Promo'),
            $site,
            $contact,
            SendContext::manual(SendClass::Marketing),
        );
        $this->assertTrue($blockedMkt->wasSuppressed());
        $this->assertSame(0, $seq);

        // Lift all; write marketing-scope
        $active = ChannelSuppression::query()->active()->where('address', 'renter@example.com')->firstOrFail();
        $active->forceFill([
            'lifted_at' => now(),
            'lift_reason' => 'test lift',
        ])->save();

        SuppressionWriter::fromUnsubscribe('renter@example.com');

        // marketing-scope blocks marketing
        $mktBlocked = app(EmailSender::class)->send(
            $this->email('renter@example.com', 'Nurture'),
            $site,
            $contact,
            SendContext::manual(SendClass::Marketing),
        );
        $this->assertTrue($mktBlocked->wasSuppressed());
        $this->assertSame('unsubscribed', $mktBlocked->suppressedReason);
        $this->assertSame(0, $seq);

        // marketing-scope allows transactional (dunning passes)
        $dunning = app(EmailSender::class)->send(
            $this->email('renter@example.com', 'Payment due'),
            $site,
            $contact,
            SendContext::manual(SendClass::Transactional),
        );
        $this->assertFalse($dunning->wasSuppressed());
        $this->assertSame('brevo-1', $dunning->providerMessageId);
        $this->assertSame(1, $seq);

        // Lifted row does not block
        $mkt = ChannelSuppression::query()->active()->where('address', 'renter@example.com')->firstOrFail();
        $mkt->forceFill([
            'lifted_at' => now(),
            'lift_reason' => 'cleared',
        ])->save();

        $afterLift = app(EmailSender::class)->send(
            $this->email('renter@example.com', 'Welcome back'),
            $site,
            $contact,
            SendContext::manual(SendClass::Marketing),
        );
        $this->assertFalse($afterLift->wasSuppressed());
        $this->assertSame('brevo-2', $afterLift->providerMessageId);
        $this->assertSame(2, $seq);
    }

    public function test_sender_only_single_gate(): void
    {
        $emailSender = (string) file_get_contents(app_path('Support/Communications/Senders/EmailSender.php'));
        $smsSender = (string) file_get_contents(app_path('Support/Communications/Senders/SmsSender.php'));

        $this->assertStringContainsString('SuppressionWriter::blocks', $emailSender);
        $this->assertStringContainsString('SuppressionWriter::blocks', $smsSender);

        $exclude = [
            app_path('Support/Communications/Senders/EmailSender.php'),
            app_path('Support/Communications/Senders/SmsSender.php'),
            app_path('Support/Communications/SuppressionWriter.php'),
            app_path('Models/ChannelSuppression.php'),
            app_path('Listeners/WriteChannelSuppression.php'),
            app_path('Http/Controllers/UnsubscribeController.php'),
            app_path('Support/Communications/DeliveryEventApplier.php'),
            app_path('Support/Communications/InboundReceiptApplier.php'),
            app_path('Support/Automation/NodeHandlers/SendEmailHandler.php'),
            app_path('Support/Automation/NodeHandlers/SendSmsHandler.php'),
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path()),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (in_array($path, $exclude, true)) {
                continue;
            }

            if (str_contains($path, DIRECTORY_SEPARATOR.'Suppression') || str_contains($path, 'UnsubscribeToken')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            $this->assertStringNotContainsString(
                'SuppressionWriter::blocks',
                $source,
                "Pre-send suppression gate found outside senders: {$path}",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/(?<!Write)ChannelSuppression::/',
                $source,
                "ChannelSuppression query found outside allowed writers: {$path}",
            );
        }

        // Handlers may observe sender result after the fact — that is not a pre-check.
        $emailHandler = (string) file_get_contents(app_path('Support/Automation/NodeHandlers/SendEmailHandler.php'));
        $this->assertStringContainsString('wasSuppressed()', $emailHandler);
        $this->assertStringNotContainsString('SuppressionWriter::blocks', $emailHandler);
    }

    private function email(string $to, string $subject = 'Hello'): EmailMessage
    {
        return new EmailMessage(
            to: [new EmailAddress($to)],
            subject: $subject,
            html: '<p>Hi</p>',
            text: 'Hi',
        );
    }
}
