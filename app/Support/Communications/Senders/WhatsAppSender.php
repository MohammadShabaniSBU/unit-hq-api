<?php

declare(strict_types=1);

namespace App\Support\Communications\Senders;

use App\Enums\ContactChannelType;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\MessageThread;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Models\WhatsappTemplate;
use App\Support\Communications\Channel;
use App\Support\Communications\ContactChannelMatcher;
use App\Support\Communications\Contracts\SendsWhatsApp;
use App\Support\Communications\Exceptions\ProviderRequestFailed;
use App\Support\Communications\Exceptions\SendRefused;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\Messages\WhatsAppSessionMessage;
use App\Support\Communications\Messages\WhatsAppTemplateMessage;
use App\Support\Communications\OutboundMessageRecorder;
use App\Support\Communications\Provider;
use App\Support\Communications\ProviderResolver;
use App\Support\Communications\Results\SendResult;
use App\Support\Communications\SendContext;
use App\Support\Communications\SuppressionWriter;
use App\Support\Communications\WhatsAppTemplateResolver;
use App\Support\Communications\WhatsAppWindow;

final class WhatsAppSender
{
    public function __construct(
        private readonly ProviderResolver $resolver,
    ) {}

    /**
     * @param  array<string, mixed>|null  $interactionMetadata
     * @param  array<string, mixed>|null  $detail
     */
    public function sendSession(
        WhatsAppSessionMessage $message,
        Site $site,
        Contact $contact,
        SendContext $context,
        MessageThread $thread,
        ?int $dealId = null,
        ?array $interactionMetadata = null,
        ?array $detail = null,
    ): SendResult {
        $this->assertConsentFloor($contact, $message->to);

        if (! WhatsAppWindow::isOpen($thread)) {
            throw SendRefused::windowClosed();
        }

        return $this->dispatch(
            mode: 'session',
            message: $message,
            site: $site,
            contact: $contact,
            context: $context,
            thread: $thread,
            bodyText: $message->body,
            dealId: $dealId,
            interactionMetadata: $interactionMetadata,
            detail: $detail,
        );
    }

    /**
     * @param  array<string, mixed>|null  $interactionMetadata
     * @param  array<string, mixed>|null  $detail
     */
    public function sendTemplate(
        WhatsAppTemplateMessage $message,
        Site $site,
        Contact $contact,
        SendContext $context,
        ?MessageThread $thread = null,
        ?int $dealId = null,
        ?array $interactionMetadata = null,
        ?array $detail = null,
    ): SendResult {
        $this->assertConsentFloor($contact, $message->to);

        $resolved = $this->resolver->resolve(Channel::Whatsapp, $site);
        $this->assertTemplateApproved(
            $resolved->account->id,
            $message->templateName,
            $message->language,
        );

        $incomingDetail = $detail ?? [];
        $templateDetail = [
            'name' => $message->templateName,
            'language' => $message->language,
            'variables' => $message->variables,
        ];
        if (isset($incomingDetail['whatsapp_template']) && is_array($incomingDetail['whatsapp_template'])) {
            $templateDetail = array_merge($incomingDetail['whatsapp_template'], $templateDetail);
            unset($incomingDetail['whatsapp_template']);
        }

        return $this->dispatch(
            mode: 'template',
            message: $message,
            site: $site,
            contact: $contact,
            context: $context,
            thread: $thread,
            bodyText: $this->templateBodyPreview($resolved->account->id, $message),
            dealId: $dealId,
            interactionMetadata: $interactionMetadata,
            detail: array_merge($incomingDetail, [
                'whatsapp_template' => $templateDetail,
            ]),
            resolvedAccountId: $resolved->account->id,
            resolvedProvider: $resolved->account->provider,
            resolvedAdapter: $resolved->require(SendsWhatsApp::class, 'sending WhatsApp'),
        );
    }

    /**
     * Resolve language via locale ladder, send, and log the choice on the message.
     *
     * @param  list<string>  $variables
     * @param  array<string, mixed>|null  $interactionMetadata
     * @param  array<string, mixed>|null  $detail
     */
    public function sendResolvedTemplate(
        string $to,
        string $templateName,
        array $variables,
        Site $site,
        Contact $contact,
        SendContext $context,
        ?MessageThread $thread = null,
        ?int $dealId = null,
        ?array $interactionMetadata = null,
        ?array $detail = null,
    ): SendResult {
        $account = $this->resolver->resolve(Channel::Whatsapp, $site)->account;

        try {
            $resolution = WhatsAppTemplateResolver::resolve(
                $account->id,
                $templateName,
                $contact,
                $site,
            );
        } catch (\RuntimeException) {
            throw SendRefused::templateNotApproved();
        }

        /** @var WhatsappTemplate $template */
        $template = $resolution['template'];

        return $this->sendTemplate(
            new WhatsAppTemplateMessage($to, $templateName, $template->language, $variables),
            $site,
            $contact,
            $context,
            $thread,
            $dealId,
            $interactionMetadata,
            array_merge($detail ?? [], [
                'whatsapp_template' => [
                    'resolution' => [
                        'preferred' => $resolution['preferred'],
                        'chosen' => $resolution['chosen'],
                        'fallback' => $resolution['used_fallback'],
                    ],
                ],
            ]),
        );
    }

    /**
     * @param  WhatsAppSessionMessage|WhatsAppTemplateMessage  $message
     * @param  array<string, mixed>|null  $interactionMetadata
     * @param  array<string, mixed>|null  $detail
     */
    private function dispatch(
        string $mode,
        object $message,
        Site $site,
        Contact $contact,
        SendContext $context,
        ?MessageThread $thread,
        string $bodyText,
        ?int $dealId,
        ?array $interactionMetadata,
        ?array $detail,
        ?int $resolvedAccountId = null,
        ?Provider $resolvedProvider = null,
        ?object $resolvedAdapter = null,
    ): SendResult {
        if ($resolvedAdapter === null) {
            $resolved = $this->resolver->resolve(Channel::Whatsapp, $site);
            $resolvedAdapter = $resolved->require(SendsWhatsApp::class, 'sending WhatsApp');
            $resolvedAccountId = $resolved->account->id;
            $resolvedProvider = $resolved->account->provider;
        }

        /** @var SendsWhatsApp $adapter */
        $adapter = $resolvedAdapter;
        $accountId = (int) $resolvedAccountId;
        if ($resolvedProvider === null) {
            throw new \LogicException('WhatsApp send requires a resolved provider.');
        }
        $provider = $resolvedProvider;

        $identity = SiteSenderIdentity::query()
            ->where('site_id', $site->id)
            ->where('channel', Channel::Whatsapp)
            ->first();

        if ($message->from === null && $identity?->from_number !== null) {
            $message = $message->withSender($identity->from_number);
        }

        $fromAddress = $message->from ?? $identity?->from_number ?? '';

        $suppression = SuppressionWriter::blocks(Channel::Whatsapp, $message->to, $context->class);
        if ($suppression !== null) {
            return $this->recordSuppressed(
                $message->to,
                $bodyText,
                $contact,
                $context,
                $fromAddress,
                $provider,
                $accountId,
                $suppression->reason->value,
                $dealId,
                $interactionMetadata,
                $thread,
            );
        }

        try {
            $result = match ($mode) {
                'session' => $adapter->sendSession($message)->withAccountId($accountId),
                default => $adapter->sendTemplate($message)->withAccountId($accountId),
            };
        } catch (ProviderRequestFailed $exception) {
            OutboundMessageRecorder::record(
                contact: $contact,
                channel: Channel::Whatsapp,
                threadKey: $message->to,
                status: MessageStatus::Failed,
                context: $context,
                fromAddress: $fromAddress,
                toAddress: $message->to,
                bodyText: $bodyText,
                bodyHtml: null,
                provider: $provider,
                accountId: $accountId,
                providerMessageId: null,
                dealId: $dealId,
                interactionMetadata: $interactionMetadata,
                thread: $thread,
            );

            throw $exception;
        }

        $recorded = OutboundMessageRecorder::record(
            contact: $contact,
            channel: Channel::Whatsapp,
            threadKey: $message->to,
            status: MessageStatus::Sent,
            context: $context,
            fromAddress: $fromAddress,
            toAddress: $message->to,
            bodyText: $bodyText,
            bodyHtml: null,
            provider: $result->provider,
            accountId: $result->accountId,
            providerMessageId: $result->providerMessageId,
            dealId: $dealId,
            interactionMetadata: $interactionMetadata,
            detail: $detail,
            thread: $thread,
        );

        return $result->withStoreIds($recorded['message']->id, $recorded['interaction']->id);
    }

    private function assertConsentFloor(Contact $contact, string $to): void
    {
        $normalized = ContactChannelMatcher::normalize(Channel::Whatsapp, $to);
        if ($normalized === '') {
            throw SendRefused::consentFloor();
        }

        $hasWhatsappChannel = ContactChannel::query()
            ->where('contact_id', $contact->id)
            ->where('type', ContactChannelType::Whatsapp)
            ->get()
            ->contains(function (ContactChannel $row) use ($normalized): bool {
                return ContactChannelMatcher::normalize(Channel::Whatsapp, (string) $row->value) === $normalized;
            });

        if (! $hasWhatsappChannel) {
            throw SendRefused::consentFloor();
        }
    }

    private function assertTemplateApproved(int $accountId, string $name, string $language): void
    {
        $template = WhatsappTemplate::query()
            ->where('communication_account_id', $accountId)
            ->where('name', $name)
            ->where('language', $language)
            ->where('status', WhatsappTemplate::STATUS_APPROVED)
            ->first();

        if ($template === null) {
            throw SendRefused::templateNotApproved();
        }
    }

    private function templateBodyPreview(int $accountId, WhatsAppTemplateMessage $message): string
    {
        $template = WhatsappTemplate::query()
            ->where('communication_account_id', $accountId)
            ->where('name', $message->templateName)
            ->where('language', $message->language)
            ->where('status', WhatsappTemplate::STATUS_APPROVED)
            ->first();

        $body = $template?->body ?? $message->templateName;

        foreach ($message->variables as $index => $value) {
            $body = str_replace('{{'.($index + 1).'}}', $value, $body);
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>|null  $interactionMetadata
     */
    private function recordSuppressed(
        string $to,
        string $bodyText,
        Contact $contact,
        SendContext $context,
        string $fromAddress,
        Provider $provider,
        int $accountId,
        string $suppressedReason,
        ?int $dealId,
        ?array $interactionMetadata,
        ?MessageThread $thread,
    ): SendResult {
        $recorded = OutboundMessageRecorder::record(
            contact: $contact,
            channel: Channel::Whatsapp,
            threadKey: $to,
            status: MessageStatus::Failed,
            context: $context,
            fromAddress: $fromAddress,
            toAddress: $to,
            bodyText: $bodyText,
            bodyHtml: null,
            provider: $provider,
            accountId: $accountId,
            providerMessageId: null,
            dealId: $dealId,
            interactionMetadata: $interactionMetadata,
            detail: ['suppressed_reason' => $suppressedReason],
            thread: $thread,
        );

        return new SendResult(
            providerMessageId: '',
            provider: $provider,
            accountId: $accountId,
            raw: ['suppressed' => true, 'reason' => $suppressedReason],
            messageId: $recorded['message']->id,
            interactionId: $recorded['interaction']->id,
            suppressedReason: $suppressedReason,
        );
    }
}
