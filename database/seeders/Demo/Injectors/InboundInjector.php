<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Injectors;

use App\Jobs\ProcessInboundWebhookEvent;
use App\Models\CommsWebhookEvent;
use App\Models\CommunicationAccount;
use App\Models\MessageThread;
use Database\Seeders\Demo\DemoWorld;
use Illuminate\Support\Str;

/**
 * Fabricates inbound email/SMS/WhatsApp payloads and enters at ProcessInboundWebhookEvent.
 */
final class InboundInjector
{
    public function __construct(private readonly DemoWorld $world) {}

    /**
     * @param  list<array<string, mixed>>|null  $attachments
     */
    public function email(
        string $from,
        string $body,
        ?MessageThread $thread = null,
        ?array $attachments = null,
        ?CommunicationAccount $account = null,
    ): CommsWebhookEvent {
        $account ??= $this->world->postmarkAccount();
        $messageId = (string) Str::uuid();
        $headers = [
            ['Name' => 'Message-ID', 'Value' => "<{$messageId}@mtasv.net>"],
        ];

        if ($thread !== null) {
            $prior = $thread->messages()->latest('id')->first();
            if ($prior !== null && filled($prior->provider_message_id)) {
                $headers[] = [
                    'Name' => 'In-Reply-To',
                    'Value' => (string) $prior->provider_message_id,
                ];
            }
        }

        $payload = [
            'From' => $from,
            'FromName' => $from,
            'FromFull' => ['Email' => $from, 'Name' => '', 'MailboxHash' => ''],
            'To' => 'inbox@example.com',
            'ToFull' => [['Email' => 'inbox@example.com', 'Name' => '', 'MailboxHash' => '']],
            'Cc' => '',
            'CcFull' => [],
            'Bcc' => '',
            'BccFull' => [],
            'OriginalRecipient' => 'inbox@example.com',
            'Subject' => $thread?->subject ?? 'Demo inbound',
            'MessageID' => $messageId,
            'ReplyTo' => '',
            'MailboxHash' => '',
            'Date' => now()->toRfc2822String(),
            'TextBody' => $body,
            'HtmlBody' => '<p>'.e($body).'</p>',
            'StrippedTextReply' => $body,
            'Tag' => '',
            'Headers' => $headers,
            'Attachments' => $attachments ?? [],
        ];

        return $this->dispatch($account, $messageId, $payload);
    }

    public function sms(
        string $from,
        string $body,
        ?CommunicationAccount $account = null,
    ): CommsWebhookEvent {
        $account ??= $this->world->smsAccount();
        $sid = 'SM'.Str::lower(Str::random(32));

        $payload = [
            'ToCountry' => 'US',
            'SmsMessageSid' => $sid,
            'NumMedia' => '0',
            'FromState' => '',
            'SmsStatus' => 'received',
            'Body' => $body,
            'FromCountry' => 'US',
            'To' => '+15550001111',
            'NumSegments' => '1',
            'MessageSid' => $sid,
            'AccountSid' => 'ACdemo000000000000000000000000000',
            'From' => $from,
            'ApiVersion' => '2010-04-01',
        ];

        return $this->dispatch($account, $sid, $payload);
    }

    public function whatsapp(
        string $from,
        string $body,
        ?CommunicationAccount $account = null,
    ): CommsWebhookEvent {
        $account ??= $this->world->whatsappAccount();
        $eventId = '01WA-DEMO-'.Str::upper(Str::random(8));

        $payload = [
            'type' => 'whatsapp_mo',
            'id' => $eventId,
            'from' => $from,
            'to' => '+15550009999',
            'body' => $body,
            'received_at' => now()->toIso8601String(),
        ];

        return $this->dispatch($account, $eventId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(
        CommunicationAccount $account,
        string $providerEventId,
        array $payload,
    ): CommsWebhookEvent {
        $row = CommsWebhookEvent::query()->create([
            'communication_account_id' => $account->id,
            'provider_event_id' => $providerEventId,
            'payload' => $payload,
            'processing_status' => 'pending',
            'received_at' => now(),
        ]);

        app()->call([new ProcessInboundWebhookEvent($row->id), 'handle']);

        return $row->fresh() ?? $row;
    }
}
