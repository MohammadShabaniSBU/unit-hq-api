<?php

declare(strict_types=1);

namespace App\Support\Automation\NodeHandlers;

use App\Enums\ContactChannelType;
use App\Enums\PlaybookKind;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\Site;
use App\Support\Automation\Contracts\NodeHandler;
use App\Support\Automation\RunContext;
use App\Support\Automation\SubjectChain;
use App\Support\Communications\Exceptions\SendRefused;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\WhatsAppSender;
use App\Support\Communications\WhatsAppPlaybookCategory;
use App\Support\Communications\WhatsAppTemplateResolver;
use App\Support\Communications\WhatsAppVariableResolver;
use App\Support\Communications\Channel;
use App\Support\Communications\ProviderResolver;
use RuntimeException;

final class SendWhatsAppTemplateHandler implements NodeHandler
{
    public function handle(
        AutomationRun $run,
        AutomationRunStep $step,
        AutomationNode $node,
        RunContext $context,
    ): array {
        $config = $node->config ?? [];
        $templateName = (string) ($config['whatsapp_template_name'] ?? '');
        if ($templateName === '') {
            throw new RuntimeException('send_whatsapp_template requires whatsapp_template_name');
        }

        /** @var array<int|string, mixed> $variableTokens */
        $variableTokens = is_array($config['variable_tokens'] ?? null)
            ? $config['variable_tokens']
            : [];

        $contact = SubjectChain::contact($run);
        if ($contact === null) {
            throw new RuntimeException('send_whatsapp_template could not resolve contact from subject chain');
        }

        $channel = SubjectChain::primaryChannel($contact, ContactChannelType::Whatsapp);
        if ($channel === null || trim($channel->value) === '') {
            return $this->skipped(
                $templateName,
                null,
                WhatsAppVariableResolver::resolveTokens($variableTokens, $context),
                'no_channel',
            );
        }

        $site = SubjectChain::site($run);
        if (! $site instanceof Site) {
            throw new RuntimeException('send_whatsapp_template requires a site for provider resolution');
        }

        $variables = WhatsAppVariableResolver::resolveTokens($variableTokens, $context);

        $playbookKind = $this->playbookKind($run);
        if ($playbookKind !== null) {
            try {
                $account = app(ProviderResolver::class)->resolve(Channel::Whatsapp, $site)->account;
                $resolution = WhatsAppTemplateResolver::resolve(
                    $account->id,
                    $templateName,
                    $contact,
                    $site,
                );
                $category = (string) $resolution['template']->category;
                if (! WhatsAppPlaybookCategory::isAllowed($playbookKind, $category)) {
                    return $this->skipped($templateName, $channel->value, $variables, 'template_not_approved');
                }
            } catch (\RuntimeException) {
                return $this->skipped($templateName, $channel->value, $variables, 'template_not_approved');
            }
        }

        $sendContext = SendContext::forRun($run, $step);
        $metadata = [
            'automation_id' => $run->automation_id,
            'automation_run_id' => $run->id,
            'source' => $sendContext->source->value,
        ];
        $dealId = $run->subject_type === 'deal' ? $run->subject_id : null;

        try {
            $result = app(WhatsAppSender::class)->sendResolvedTemplate(
                to: $channel->value,
                templateName: $templateName,
                variables: $variables,
                site: $site,
                contact: $contact,
                context: $sendContext,
                dealId: $dealId,
                interactionMetadata: $metadata,
            );
        } catch (SendRefused $e) {
            $reason = match ($e->reasonKey) {
                'whatsapp.consent_floor' => 'no_channel',
                'whatsapp.template_not_approved' => 'template_not_approved',
                default => 'template_not_approved',
            };

            return $this->skipped($templateName, $channel->value, $variables, $reason);
        }

        if ($result->wasSuppressed()) {
            return [
                'to' => $channel->value,
                'body' => null,
                'channel' => 'whatsapp',
                'whatsapp_template_name' => $templateName,
                'variables' => $variables,
                'provider_message_id' => null,
                'communication_account_id' => $result->accountId,
                'interaction_id' => $result->interactionId,
                'message_id' => $result->messageId,
                'skipped_reason' => 'suppressed',
            ];
        }

        return [
            'to' => $channel->value,
            'body' => null,
            'channel' => 'whatsapp',
            'whatsapp_template_name' => $templateName,
            'variables' => $variables,
            'provider_message_id' => $result->providerMessageId,
            'communication_account_id' => $result->accountId,
            'interaction_id' => $result->interactionId,
            'message_id' => $result->messageId,
        ];
    }

    private function playbookKind(AutomationRun $run): ?PlaybookKind
    {
        $run->loadMissing('automation.playbook');
        $kind = $run->automation?->playbook?->kind;

        return $kind instanceof PlaybookKind ? $kind : null;
    }

    /**
     * @param  list<string>  $variables
     * @return array<string, mixed>
     */
    private function skipped(string $templateName, ?string $to, array $variables, string $reason): array
    {
        return [
            'to' => $to,
            'body' => null,
            'channel' => 'whatsapp',
            'whatsapp_template_name' => $templateName,
            'variables' => $variables,
            'provider_message_id' => null,
            'communication_account_id' => null,
            'interaction_id' => null,
            'message_id' => null,
            'skipped_reason' => $reason,
        ];
    }
}
