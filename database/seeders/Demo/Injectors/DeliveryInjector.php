<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Injectors;

use App\Jobs\ProcessDeliveryWebhookEvent;
use App\Models\CommsWebhookEvent;
use App\Models\CommunicationAccount;
use App\Models\Message;
use Database\Seeders\Demo\DemoWorld;
use InvalidArgumentException;

/**
 * Fabricates delivery-status payloads and enters at ProcessDeliveryWebhookEvent.
 */
final class DeliveryInjector
{
    public function __construct(private readonly DemoWorld $world) {}

    /**
     * @param  'delivered'|'opened'|'bounced'|'soft_bounce'|'failed'|'spam'|'unsubscribed'  $status
     */
    public function event(
        Message $message,
        string $status,
        ?CommunicationAccount $account = null,
    ): CommsWebhookEvent {
        $account ??= $message->communicationAccount ?? $this->world->emailAccount();
        $providerMessageId = (string) ($message->provider_message_id ?? '');
        if ($providerMessageId === '') {
            throw new InvalidArgumentException('Message requires provider_message_id for delivery injection.');
        }

        $brevoEvent = match ($status) {
            'delivered' => 'delivered',
            'opened' => 'opened',
            'bounced', 'hard_bounce' => 'hardBounce',
            'soft_bounce' => 'softBounce',
            'failed' => 'error',
            'spam' => 'spam',
            'unsubscribed' => 'unsubscribed',
            default => throw new InvalidArgumentException("Unknown delivery status: {$status}"),
        };

        $nativeId = (string) random_int(1_000_000_000, 2_000_000_000);
        $payload = [
            'id' => (int) $nativeId,
            'event' => $brevoEvent,
            'email' => $message->to_address,
            'date' => now()->toIso8601String(),
            'message-id' => $providerMessageId,
            'ts_event' => now()->timestamp,
            'subject' => 'Demo delivery',
        ];

        if ($brevoEvent === 'hardBounce' || $brevoEvent === 'softBounce') {
            $payload['reason'] = 'mailbox_not_found';
        }

        $row = CommsWebhookEvent::query()->create([
            'communication_account_id' => $account->id,
            'provider_event_id' => $nativeId,
            'payload' => $payload,
            'processing_status' => 'pending',
            'received_at' => now(),
        ]);

        app()->call([new ProcessDeliveryWebhookEvent($row->id), 'handle']);

        return $row->fresh() ?? $row;
    }
}
