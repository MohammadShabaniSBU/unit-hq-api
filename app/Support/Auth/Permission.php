<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * Complete permission vocabulary. Stored as strings on role_permissions;
 * never invent permissions at runtime — a missing enum case is a defect.
 */
enum Permission: string
{
    // Leasing
    case ContactView = 'contact.view';
    case ContactManage = 'contact.manage';
    case DealManage = 'deal.manage';
    case OfferManage = 'offer.manage';
    case OfferSend = 'offer.send';
    case ReservationManage = 'reservation.manage';
    case ContractView = 'contract.view';
    case ContractSign = 'contract.sign';
    case ContractVacate = 'contract.vacate';
    case ContractTransfer = 'contract.transfer';
    case ContractRateChange = 'contract.rate_change';

    // Facility
    case UnitView = 'unit.view';
    case UnitManage = 'unit.manage';
    case UnitHoldManage = 'unit.hold.manage';
    case SiteManage = 'site.manage';
    case CatalogueManage = 'catalogue.manage';

    // Billing & fiscal
    case InvoiceView = 'invoice.view';
    case InvoiceIssue = 'invoice.issue';
    case InvoiceRectify = 'invoice.rectify';
    case PaymentView = 'payment.view';
    case PaymentRecord = 'payment.record';
    case PaymentRefund = 'payment.refund';
    case BillingRunExecute = 'billing.run.execute';
    case BillingSettingsManage = 'billing.settings.manage';
    case TaxRateManage = 'tax.rate.manage';
    case LegalEntityManage = 'legal_entity.manage';

    // Delinquency
    case DelinquencyView = 'delinquency.view';
    case DelinquencyAct = 'delinquency.act';
    case DelinquencyWriteOff = 'delinquency.write_off';

    // Communications
    case InboxView = 'inbox.view';
    case InboxSend = 'inbox.send';
    case InboxAssign = 'inbox.assign';
    case CallPlace = 'call.place';
    case TemplateManage = 'template.manage';

    // Operations
    case AutomationView = 'automation.view';
    case AutomationManage = 'automation.manage';
    case PlaybookManage = 'playbook.manage';
    case AccessView = 'access.view';
    case AccessManage = 'access.manage';
    case EsignSend = 'esign.send';

    // AI
    case AiSummaryView = 'ai_summary.view';
    case AiSummaryGenerate = 'ai_summary.generate';
    case AiAgentUse = 'ai_agent.use';
    case AgentActionApprove = 'agent_action.approve';
    case CopilotVoiceUse = 'copilot_voice.use';
    case AiAgentBindingManage = 'ai_agent_binding.manage';

    // Cross-cutting
    case ReportView = 'report.view';
    case ReportFinancialView = 'report.financial.view';
    case ActivityView = 'activity.view';
    case CredentialManage = 'credential.manage';
    case SettingsManage = 'settings.manage';
    case RbacManage = 'rbac.manage';

    public function domain(): string
    {
        $dot = strpos($this->value, '.');

        return $dot === false ? $this->value : substr($this->value, 0, $dot);
    }

    public function i18nKey(): string
    {
        return 'permissions.'.$this->value;
    }

    public function isView(): bool
    {
        return str_ends_with($this->value, '.view');
    }
}
