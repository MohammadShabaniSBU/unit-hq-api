<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Tools;

use App\Models\Contact;
use App\Models\ContactVerification;
use App\Models\Message;
use App\Models\Site;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\ToolErrorCode;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Identity\MaskedDestination;
use App\Support\Ai\Identity\VerificationChallenge;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\Tools\ToolResult;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageSource;
use App\Support\Communications\SuppressionReason;
use App\Support\Communications\SuppressionScope;
use App\Support\Communications\SuppressionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class IdentityVerificationToolTest extends TestCase
{
    use DispatchesAgentTools;
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    #[Test]
    public function request_code_sends_to_an_on_file_channel_and_masks_the_destination(): void
    {
        [$contact, $site, $principal, $ctx] = $this->assertedWorld();
        $this->givePrimaryEmail($contact, 'ana.ruiz@example.com');

        $result = $this->dispatchTool('concierge', 'identity.request_code', $principal, [
            'channel_type' => 'email',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('a•••@example.com', $result->data['destination_masked']);
        $this->assertSame('email', $result->data['channel_type']);
        $this->assertStringContainsString('a•••@example.com', $result->display);
        $this->assertStringNotContainsString('ana.ruiz@example.com', $result->display);

        $row = ContactVerification::query()->firstOrFail();
        $this->assertSame($contact->id, $row->contact_id);
        $this->assertSame($ctx->conversation->id, $row->agent_conversation_id);
        $this->assertSame($site->id, $row->site_id);
        $this->assertNull($row->consumed_at);
        $this->assertSame(64, strlen($row->code_hash));

        $message = Message::query()->firstOrFail();
        $this->assertSame(MessageSource::System, $message->source);
        $this->assertSame('ana.ruiz@example.com', $message->to_address);
        $this->assertMatchesRegularExpression('/\b\d{6}\b/', (string) $message->body_text);
        $this->assertStringNotContainsString((string) $message->body_text, $result->display);
    }

    #[Test]
    public function a_named_destination_is_ignored_and_the_on_file_channel_is_used(): void
    {
        [$contact, , $principal, $ctx] = $this->assertedWorld();
        $this->givePrimaryEmail($contact, 'on-file@example.com');

        $result = $this->dispatchTool('concierge', 'identity.request_code', $principal, [
            'channel_type' => 'email',
            'destination' => 'attacker@evil.test',
            'email' => 'attacker@evil.test',
            'phone' => '+15550009999',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('on-file@example.com', Message::query()->value('to_address'));
        Http::assertSent(function ($request): bool {
            $data = $request->data();
            $to = $data['to'][0]['email'] ?? null;

            return $to === 'on-file@example.com';
        });
    }

    #[Test]
    public function suppressed_address_returns_recovery_and_does_not_send(): void
    {
        [$contact, , $principal, $ctx] = $this->assertedWorld();
        $this->givePrimaryEmail($contact, 'blocked@example.com');
        SuppressionWriter::write(
            Channel::Email,
            'blocked@example.com',
            SuppressionScope::All,
            SuppressionReason::HardBounce,
        );

        $result = $this->dispatchTool('concierge', 'identity.request_code', $principal, [
            'channel_type' => 'email',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(ToolErrorCode::Unavailable, $result->error?->errorCode);
        $this->assertSame('agent.escalate', $result->error?->recovery['tool'] ?? null);
        $this->assertSame(0, ContactVerification::query()->count());
        $this->assertSame(0, Message::query()->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function missing_channel_returns_recovery_and_does_not_send(): void
    {
        [, , $principal, $ctx] = $this->assertedWorld();

        $result = $this->dispatchTool('concierge', 'identity.request_code', $principal, [
            'channel_type' => 'sms',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(ToolErrorCode::Unavailable, $result->error?->errorCode);
        $this->assertSame('agent.escalate', $result->error?->recovery['tool'] ?? null);
        $this->assertSame(0, ContactVerification::query()->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function request_code_sends_sms_to_the_phone_channel(): void
    {
        [$contact, $site, $principal, $ctx] = $this->assertedWorld();
        $this->seedSmsAccount($site);
        $this->givePrimaryPhone($contact, '+15551234417');

        $result = $this->dispatchTool('concierge', 'identity.request_code', $principal, [
            'channel_type' => 'sms',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('•••• 4417', $result->data['destination_masked']);
        $this->assertTrue($result->facts->contains('4417'));
        $this->assertTrue($result->facts->contains('•••• 4417'));
        $this->assertSame('+15551234417', Message::query()->value('to_address'));
    }

    #[Test]
    public function hourly_issue_cap_fails_closed(): void
    {
        $seq = 0;
        Http::fake([
            'api.brevo.com/v3/smtp/email' => function () use (&$seq) {
                $seq++;

                return Http::response(['messageId' => 'brevo-cap-'.$seq], 201);
            },
        ]);

        [$contact, , $principal, $ctx] = $this->assertedWorld(fakeProviders: false);
        $this->givePrimaryEmail($contact, 'cap@example.com');

        for ($i = 0; $i < 3; $i++) {
            $ok = $this->dispatchTool('concierge', 'identity.request_code', $principal, [
                'channel_type' => 'email',
            ], $ctx);
            $this->assertSame(ToolInvocationStatus::Ok, $ok->status, "issue {$i}");
        }

        $blocked = $this->dispatchTool('concierge', 'identity.request_code', $principal, [
            'channel_type' => 'email',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Error, $blocked->status);
        $this->assertSame(3, ContactVerification::query()->count());
    }

    #[Test]
    public function wrong_code_increments_attempts_and_the_sixth_fails_closed(): void
    {
        [$contact, , $principal, $ctx] = $this->assertedWorld();
        $channel = $this->givePrimaryEmail($contact, 'try@example.com');
        $this->openChallenge($contact->id, $channel->id, '654321');

        for ($i = 1; $i <= 5; $i++) {
            $result = $this->dispatchTool('concierge', 'identity.verify_code', $principal, [
                'code' => '000000',
            ], $ctx);
            $this->assertSame(ToolInvocationStatus::Error, $result->status);
            $this->assertSame($i, ContactVerification::query()->value('attempts'));
            $this->assertSame('invalid', $this->opaqueReason($result));
        }

        $sixth = $this->dispatchTool('concierge', 'identity.verify_code', $principal, [
            'code' => '000000',
        ], $ctx);
        $this->assertSame(ToolInvocationStatus::Error, $sixth->status);
        $this->assertSame('invalid', $this->opaqueReason($sixth));
        $this->assertSame(5, ContactVerification::query()->value('attempts'));
        $this->assertNull(ContactVerification::query()->value('consumed_at'));
    }

    #[Test]
    public function expired_code_fails_at_read_time_without_revealing_why(): void
    {
        [$contact, , $principal, $ctx] = $this->assertedWorld();
        $channel = $this->givePrimaryEmail($contact, 'old@example.com');
        $this->openChallenge($contact->id, $channel->id, '111111', expiresAt: now()->subMinute());

        $result = $this->dispatchTool('concierge', 'identity.verify_code', $principal, [
            'code' => '111111',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame('invalid', $this->opaqueReason($result));
        $this->assertSame(0, ContactVerification::query()->value('attempts'));
    }

    #[Test]
    public function consumed_code_cannot_be_reused(): void
    {
        [$contact, , $principal, $ctx] = $this->assertedWorld();
        $channel = $this->givePrimaryEmail($contact, 'once@example.com');
        $this->openChallenge($contact->id, $channel->id, '222222');

        $first = $this->dispatchTool('concierge', 'identity.verify_code', $principal, [
            'code' => '222222',
        ], $ctx);
        $this->assertSame(ToolInvocationStatus::Ok, $first->status);
        $this->assertTrue($first->data['ok'] ?? false);

        $second = $this->dispatchTool('concierge', 'identity.verify_code', $principal, [
            'code' => '222222',
        ], $ctx);
        $this->assertSame(ToolInvocationStatus::Error, $second->status);
        $this->assertSame('invalid', $this->opaqueReason($second));
        $this->assertNotNull(ContactVerification::query()->value('consumed_at'));
    }

    #[Test]
    public function a_close_code_is_indistinguishable_from_any_other_failure(): void
    {
        [$contact, , $principal, $ctx] = $this->assertedWorld();
        $channel = $this->givePrimaryEmail($contact, 'near@example.com');
        $this->openChallenge($contact->id, $channel->id, '333333');

        $close = $this->dispatchTool('concierge', 'identity.verify_code', $principal, [
            'code' => '333334',
        ], $ctx);
        $wrongContact = $this->dispatchTool('concierge', 'identity.verify_code', $principal, [
            'code' => '999999',
        ], $ctx);

        $this->assertSame($this->opaqueReason($close), $this->opaqueReason($wrongContact));
        $this->assertSame($close->display, $wrongContact->display);
    }

    #[Test]
    public function contact_verifications_is_named_in_the_redaction_scope_comment(): void
    {
        $config = (string) file_get_contents(config_path('redaction.php'));
        $this->assertStringContainsString('contact_verifications', $config);
        $this->assertStringContainsString('AR-03', $config);
    }

    #[Test]
    public function mask_licenses_the_phone_tail_for_grounding(): void
    {
        $masked = MaskedDestination::maskPhone('+15550000412');
        $this->assertSame('•••• 0412', $masked);

        $facts = MaskedDestination::license(new FactBag, $masked, '+15550000412');
        $this->assertTrue($facts->contains('0412'));
        $this->assertTrue($facts->contains('412'));
        $this->assertTrue($facts->contains('•••• 0412'));
    }

    /**
     * @return array{0: Contact, 1: Site, 2: AgentPrincipal, 3: AgentContext}
     */
    /**
     * @return array{0: Contact, 1: Site, 2: AgentPrincipal, 3: AgentContext}
     */
    private function assertedWorld(bool $fakeProviders = true): array
    {
        if ($fakeProviders) {
            $this->fakeCommunicationProviders();
        }
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $contact = Contact::factory()->create();
        $principal = AgentPrincipal::channelAsserted($contact->id, $site->id, 'en');
        $ctx = $this->writeContext($principal, 'concierge');

        return [$contact, $site, $principal, $ctx];
    }

    private function openChallenge(int $contactId, int $channelId, string $code, $expiresAt = null): ContactVerification
    {
        return ContactVerification::query()->create([
            'contact_id' => $contactId,
            'contact_channel_id' => $channelId,
            'code_hash' => VerificationChallenge::hash($code),
            'attempts' => 0,
            'expires_at' => $expiresAt ?? now()->addMinutes(10),
            'created_at' => now(),
        ]);
    }

    private function opaqueReason(ToolResult $result): string
    {
        $this->assertSame(ToolErrorCode::Unavailable, $result->error?->errorCode);

        return 'invalid';
    }
}
