<?php

declare(strict_types=1);

namespace App\Support\Automation\NodeHandlers;

use App\Enums\ContactChannelType;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\Site;
use App\Models\TemplateFamily;
use App\Support\Automation\Contracts\NodeHandler;
use App\Support\Automation\RunContext;
use App\Support\Automation\SubjectChain;
use App\Support\Automation\TokenResolver;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\SmsSender;
use App\Support\Communications\SmsTemplateRenderer;
use App\Support\Communications\TemplateResolver;
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
        $this->assertXor($config);

        $body = $this->resolveBody($config, $context, $run);

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
                'segments' => (new SmsMessage('+0', $body))->segmentCount(),
                'provider_message_id' => null,
                'communication_account_id' => null,
                'interaction_id' => null,
                'message_id' => null,
                'skipped_reason' => 'no_channel',
            ];
        }

        $site = SubjectChain::site($run);
        if (! $site instanceof Site) {
            throw new RuntimeException('send_sms requires a site for provider resolution');
        }

        $sendContext = SendContext::forRun($run, $step);
        $metadata = [
            'automation_id' => $run->automation_id,
            'automation_run_id' => $run->id,
            'source' => $sendContext->source->value,
        ];
        $dealId = $run->subject_type === 'deal' ? $run->subject_id : null;

        $result = app(SmsSender::class)->send(
            new SmsMessage(to: $channel->value, body: $body),
            $site,
            $contact,
            $sendContext,
            $dealId,
            $metadata,
        );

        if ($result->wasSuppressed()) {
            return [
                'to' => $channel->value,
                'body' => $body,
                'channel' => 'sms',
                'segments' => (new SmsMessage($channel->value, $body))->segmentCount(),
                'provider_message_id' => null,
                'communication_account_id' => $result->accountId,
                'interaction_id' => $result->interactionId,
                'message_id' => $result->messageId,
                'skipped_reason' => 'suppressed',
            ];
        }

        return [
            'to' => $channel->value,
            'body' => $body,
            'channel' => 'sms',
            'segments' => (new SmsMessage($channel->value, $body))->segmentCount(),
            'provider_message_id' => $result->providerMessageId,
            'communication_account_id' => $result->accountId,
            'interaction_id' => $result->interactionId,
            'message_id' => $result->messageId,
        ];
    }

    /** @param  array<string, mixed>  $config */
    private function assertXor(array $config): void
    {
        $templateId = $config['template_family_id'] ?? $config['templateId'] ?? null;
        $bodyType = (string) ($config['bodyType'] ?? $config['body_type'] ?? 'custom');
        $hasInline = array_key_exists('body', $config) && $config['body'] !== null && $config['body'] !== '';

        if ($templateId !== null && $bodyType === 'custom' && $hasInline) {
            throw new RuntimeException('send_sms params must be template_family_id XOR inline body');
        }

        if ($bodyType === 'template' && $templateId === null) {
            throw new RuntimeException('send_sms template path requires template_family_id');
        }
    }

    /** @param  array<string, mixed>  $config */
    private function resolveBody(array $config, RunContext $context, AutomationRun $run): string
    {
        $tokens = (bool) ($config['tokens'] ?? true);
        $templateId = $config['template_family_id'] ?? $config['templateId'] ?? null;
        $bodyType = (string) ($config['bodyType'] ?? $config['body_type'] ?? 'custom');

        if ($bodyType === 'template' || $templateId !== null) {
            if ($templateId === null) {
                throw new RuntimeException('send_sms template path requires template_family_id');
            }

            $family = TemplateFamily::query()->with('variants')->find((int) $templateId);
            if ($family === null) {
                throw new RuntimeException("send_sms template family [{$templateId}] not found");
            }

            $contact = SubjectChain::contact($run);
            if ($contact === null) {
                throw new RuntimeException('send_sms template path requires a contact');
            }

            $site = SubjectChain::site($run);
            $variant = TemplateResolver::variant($family, $contact, $site instanceof Site ? $site : null);
            $rendered = SmsTemplateRenderer::render($variant, $context);

            return $rendered['text'];
        }

        $rawBody = (string) ($config['body'] ?? '');

        return $tokens ? TokenResolver::resolve($rawBody, $context) : $rawBody;
    }
}
