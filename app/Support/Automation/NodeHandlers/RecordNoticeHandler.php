<?php

declare(strict_types=1);

namespace App\Support\Automation\NodeHandlers;

use App\Enums\ContractNoticeType;
use App\Enums\DelinquencyStepAction;
use App\Enums\DelinquencyStepTrigger;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\ContractNotice;
use App\Models\DelinquencyStep;
use App\Support\Automation\Contracts\NodeHandler;
use App\Support\Automation\RunContext;
use App\Support\Automation\SubjectChain;
use RuntimeException;

final class RecordNoticeHandler implements NodeHandler
{
    public function handle(
        AutomationRun $run,
        AutomationRunStep $step,
        AutomationNode $node,
        RunContext $context,
    ): array {
        $config = $node->config ?? [];
        $noticeTypeRaw = (string) ($config['notice_type'] ?? 'payment_reminder');

        try {
            $noticeType = ContractNoticeType::from($noticeTypeRaw);
        } catch (\ValueError) {
            throw new RuntimeException("record_notice invalid notice_type [{$noticeTypeRaw}]");
        }

        $contract = SubjectChain::contract($run);
        if ($contract === null) {
            throw new RuntimeException('record_notice requires a contract in the subject chain');
        }

        $sentAt = null;
        $sentChannel = null;
        $sentTo = null;

        $fromKey = $config['sent_from_node_key'] ?? $config['sentFromNodeKey'] ?? null;
        if (is_string($fromKey) && $fromKey !== '') {
            $prior = $context->get('steps.'.$fromKey);
            if (is_array($prior) && ($prior['skipped_reason'] ?? null) !== 'no_channel') {
                $to = $prior['to'] ?? null;
                if (is_string($to) && $to !== '') {
                    $sentAt = now();
                    $sentChannel = is_string($prior['channel'] ?? null)
                        ? (string) $prior['channel']
                        : 'email';
                    $sentTo = $to;
                }
            }
        }

        $notice = ContractNotice::query()->create([
            'contract_id' => $contract->id,
            'notice_type' => $noticeType,
            'effective_date' => null,
            'required_by' => null,
            'sent_at' => $sentAt,
            'sent_channel' => $sentChannel,
            'sent_to' => $sentTo,
            'document_ref' => null,
            'short_notice_reason' => null,
            'contract_item_id' => null,
            'created_by' => null,
        ]);

        $delinquencyStepId = null;
        $case = SubjectChain::delinquency($run);
        if ($case !== null) {
            $timeline = DelinquencyStep::query()->create([
                'delinquency_id' => $case->id,
                'policy_step_id' => null,
                'action' => DelinquencyStepAction::RecordNotice,
                'executed_on' => now()->toDateString(),
                'trigger' => DelinquencyStepTrigger::Playbook,
                'contract_notice_id' => $notice->id,
                'detail' => [
                    'automation_id' => $run->automation_id,
                    'automation_run_id' => $run->id,
                    'source' => 'playbook',
                ],
                'created_by' => null,
            ]);
            $delinquencyStepId = $timeline->id;
        }

        return [
            'contract_notice_id' => $notice->id,
            'delinquency_step_id' => $delinquencyStepId,
            'notice_type' => $noticeType->value,
            'sent_at' => $sentAt?->toIso8601String(),
            'sent_channel' => $sentChannel,
            'sent_to' => $sentTo,
        ];
    }
}
