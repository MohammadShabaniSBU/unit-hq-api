<?php

declare(strict_types=1);

namespace App\Support\Automation\NodeHandlers;

use App\Enums\ContactChannelType;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\Interaction;
use App\Models\Site;
use App\Support\Automation\Contracts\NodeHandler;
use App\Support\Automation\RunContext;
use App\Support\Automation\SubjectChain;
use App\Support\Automation\TokenResolver;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\Senders\SmsSender;
use RuntimeException;

final class SendSmsHandler implements NodeHandler
{
    public function handle(
        AutomationRun $run,
        AutomationRunStep $step,
        AutomationNode $node,
        RunContext $context,
    ): array {
        $config = $node->config ?? [];
        $tokens = (bool) ($config['tokens'] ?? true);
        $rawBody = (string) ($config['body'] ?? '');
        $body = $tokens ? TokenResolver::resolve($rawBody, $context) : $rawBody;

        $contact = SubjectChain::contact($run);
        if ($contact === null) {
            throw new RuntimeException('send_sms could not resolve contact from subject chain');
        }

        $channel = SubjectChain::primaryChannel($contact, ContactChannelType::Phone);
        if ($channel === null || trim($channel->value) === '') {
            return [
                'to' => null,
                'body' => $body,
                'channel' => 'sms',
                'provider_message_id' => null,
                'communication_account_id' => null,
                'interaction_id' => null,
                'skipped_reason' => 'no_channel',
            ];
        }

        $site = SubjectChain::site($run);
        if (! $site instanceof Site) {
            throw new RuntimeException('send_sms requires a site for provider resolution');
        }

        $result = app(SmsSender::class)->send(
            new SmsMessage(to: $channel->value, body: $body),
            $site,
            $contact,
        );

        $interaction = Interaction::query()
            ->where('contact_id', $contact->id)
            ->where('provider_message_id', $result->providerMessageId)
            ->latest('id')
            ->first();

        if ($interaction !== null) {
            $metadata = $interaction->metadata ?? [];
            $metadata['automation_id'] = $run->automation_id;
            $metadata['automation_run_id'] = $run->id;
            $metadata['source'] = 'automation';
            if ($run->subject_type === 'deal') {
                $interaction->deal_id = $run->subject_id;
            }
            $interaction->metadata = $metadata;
            $interaction->save();
        }

        return [
            'to' => $channel->value,
            'body' => $body,
            'channel' => 'sms',
            'provider_message_id' => $result->providerMessageId,
            'communication_account_id' => $result->accountId,
            'interaction_id' => $interaction?->id,
        ];
    }
}
