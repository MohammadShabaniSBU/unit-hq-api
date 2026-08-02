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
use App\Support\Communications\EmailTemplateRenderer;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\EmailSender;
use App\Support\Communications\TemplateResolver;
use RuntimeException;

final class SendEmailHandler implements NodeHandler
{
    public function handle(
        AutomationRun $run,
        AutomationRunStep $step,
        AutomationNode $node,
        RunContext $context,
    ): array {
        $config = $node->config ?? [];
        $this->assertXor($config);

        $contact = SubjectChain::contact($run);
        if ($contact === null) {
            throw new RuntimeException('send_email could not resolve contact from subject chain');
        }

        $channel = SubjectChain::primaryChannel($contact, ContactChannelType::Email);
        if ($channel === null || trim($channel->value) === '') {
            [$subject, $bodyHtml, $bodyText] = $this->resolveContent(
                $config,
                $context,
                $contact,
                SubjectChain::site($run),
            );

            return [
                'to' => null,
                'subject' => $subject,
                'body' => $bodyText !== '' ? $bodyText : $bodyHtml,
                'channel' => 'email',
                'provider_message_id' => null,
                'communication_account_id' => null,
                'interaction_id' => null,
                'message_id' => null,
                'skipped_reason' => 'no_channel',
            ];
        }

        [$subject, $bodyHtml, $bodyText, $warnings] = $this->resolveContent(
            $config,
            $context,
            $contact,
            SubjectChain::site($run),
        );

        $site = SubjectChain::site($run);
        if (! $site instanceof Site) {
            throw new RuntimeException('send_email requires a site for provider resolution');
        }

        $sendContext = SendContext::forRun($run, $step);
        $metadata = [
            'automation_id' => $run->automation_id,
            'automation_run_id' => $run->id,
            'source' => $sendContext->source->value,
        ];
        $dealId = $run->subject_type === 'deal' ? $run->subject_id : null;

        $detail = $warnings !== [] ? ['token_warnings' => $warnings] : null;

        $result = app(EmailSender::class)->send(
            new EmailMessage(
                to: [new EmailAddress($channel->value)],
                subject: $subject,
                html: $bodyHtml,
                text: $bodyText !== '' ? $bodyText : strip_tags($bodyHtml),
            ),
            $site,
            $contact,
            $sendContext,
            $dealId,
            $metadata,
            null,
            $detail,
        );

        if ($result->wasSuppressed()) {
            return [
                'to' => $channel->value,
                'subject' => $subject,
                'body' => $bodyText !== '' ? $bodyText : $bodyHtml,
                'channel' => 'email',
                'provider_message_id' => null,
                'communication_account_id' => $result->accountId,
                'interaction_id' => $result->interactionId,
                'message_id' => $result->messageId,
                'skipped_reason' => 'suppressed',
            ];
        }

        return [
            'to' => $channel->value,
            'subject' => $subject,
            'body' => $bodyText !== '' ? $bodyText : $bodyHtml,
            'channel' => 'email',
            'provider_message_id' => $result->providerMessageId,
            'communication_account_id' => $result->accountId,
            'interaction_id' => $result->interactionId,
            'message_id' => $result->messageId,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: string, 2: string, 3: list<string>} subject, html, text, warnings
     */
    private function resolveContent(
        array $config,
        RunContext $context,
        \App\Models\Contact $contact,
        mixed $site,
    ): array {
        $bodyType = (string) ($config['bodyType'] ?? $config['body_type'] ?? 'custom');
        $templateId = $config['template_family_id']
            ?? $config['templateId']
            ?? $config['template_id']
            ?? $config['email_template_id']
            ?? null;

        if ($bodyType === 'template' || $templateId !== null) {
            if ($templateId === null) {
                throw new RuntimeException('send_email template path requires template_family_id');
            }

            $family = TemplateFamily::query()->with('variants')->find($templateId);
            if ($family === null) {
                throw new RuntimeException("send_email template family [{$templateId}] not found");
            }

            $siteModel = $site instanceof Site ? $site : null;
            $variant = TemplateResolver::variant($family, $contact, $siteModel);

            $subjectOverride = null;
            if (isset($config['subject'])) {
                $resolved = TokenResolver::resolveValueSource($config['subject'], $context);
                $subjectOverride = is_string($resolved) ? $resolved : (string) ($resolved ?? '');
            }

            $rendered = EmailTemplateRenderer::render($variant, $context, $subjectOverride);

            return [$rendered['subject'], $rendered['html'], $rendered['text'], $rendered['warnings']];
        }

        $subject = TokenResolver::resolveValueSource($config['subject'] ?? null, $context);
        $subject = is_string($subject) ? $subject : (string) ($subject ?? '');

        $resolved = TokenResolver::resolveValueSource($config['body'] ?? null, $context);
        $body = is_string($resolved) ? $resolved : '';

        return [$subject, $body, $body, []];
    }

    /** @param  array<string, mixed>  $config */
    private function assertXor(array $config): void
    {
        $bodyType = (string) ($config['bodyType'] ?? $config['body_type'] ?? 'custom');
        $templateId = $config['template_family_id']
            ?? $config['templateId']
            ?? $config['template_id']
            ?? $config['email_template_id']
            ?? null;
        $hasInlineBody = array_key_exists('body', $config) && $config['body'] !== null && $config['body'] !== '';

        if ($templateId !== null && $bodyType === 'custom' && $hasInlineBody) {
            throw new RuntimeException('send_email params must be template_family_id XOR inline subject/body');
        }

        if ($templateId !== null && $bodyType !== 'template' && $bodyType !== 'custom') {
            throw new RuntimeException('send_email bodyType must be template or custom');
        }

        if ($bodyType === 'template' && $templateId === null) {
            throw new RuntimeException('send_email template path requires template_family_id');
        }
    }
}
