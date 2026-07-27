<?php

declare(strict_types=1);

namespace App\Support\Automation\NodeHandlers;

use App\Mail\AutomationEmail;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\Contact;
use App\Models\Interaction;
use App\Support\Automation\Contracts\NodeHandler;
use App\Support\Automation\RunContext;
use App\Support\Automation\TokenResolver;
use Illuminate\Support\Facades\Mail;
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

        $to = TokenResolver::resolveValueSource($config['to'] ?? null, $context);
        $to = is_string($to) ? trim($to) : '';

        if ($to === '') {
            throw new RuntimeException('send_email missing recipient');
        }

        $subject = TokenResolver::resolveValueSource($config['subject'] ?? null, $context);
        $subject = is_string($subject) ? $subject : (string) ($subject ?? '');

        $body = '';
        $bodyType = (string) ($config['bodyType'] ?? $config['body_type'] ?? 'custom');

        if ($bodyType === 'custom') {
            $bodySource = $config['body'] ?? null;
            $resolved = TokenResolver::resolveValueSource($bodySource, $context);
            $body = is_string($resolved) ? $resolved : '';
        } else {
            // Template id path — render as placeholder body until email-builder merge ships.
            $templateId = $config['templateId'] ?? $config['template_id'] ?? null;
            $body = $templateId !== null
                ? "(template #{$templateId})"
                : '';
        }

        Mail::to($to)->send(new AutomationEmail($subject, $body));

        $contactId = null;
        if ($run->subject_type === 'contact' && $run->subject_id !== null) {
            $contactId = (int) $run->subject_id;
        } elseif (filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $contactId = Contact::query()->where('email', $to)->value('id');
        }

        $interactionId = null;
        if ($contactId !== null) {
            $interaction = Interaction::query()->create([
                'contact_id' => $contactId,
                'deal_id' => $run->subject_type === 'deal' ? $run->subject_id : null,
                'channel' => 'email',
                'direction' => 'outbound',
                'occurred_at' => now(),
                'content' => $body,
                'summary' => $subject !== '' ? $subject : 'Automation email',
                'metadata' => [
                    'automation_id' => $run->automation_id,
                    'automation_run_id' => $run->id,
                    'source' => 'automation',
                ],
            ]);
            $interactionId = $interaction->id;
        }

        return [
            'to' => $to,
            'subject' => $subject,
            'interaction_id' => $interactionId,
        ];
    }
}
