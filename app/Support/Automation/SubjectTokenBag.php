<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\DealStatus;
use App\Models\AutomationRun;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Support\Communications\ComposerIdentity;

/**
 * Friendly token roots for playbook sends: contact.*, contract.*, deal.*, pay_link.
 *
 * pay_link is an S10 placeholder — present so templates compile/render without blanks.
 */
final class SubjectTokenBag
{
    /**
     * Friendly token paths surfaced in the inbox composer token menu.
     *
     * @return list<string>
     */
    public static function vocabulary(): array
    {
        return [
            'contact.first_name',
            'contact.last_name',
            'contact.name',
            'contact.email',
            'contact.company',
            'contact.id',
            'contract.id',
            'contract.balance_owed',
            'contract.currency',
            'deal.id',
            'deal.status',
            'pay_link',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forContact(Contact $contact): array
    {
        $bag = [
            'contact' => [
                'id' => $contact->id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'name' => trim($contact->first_name.' '.$contact->last_name),
                'email' => $contact->email,
                'company' => $contact->company,
            ],
        ];

        $contract = ComposerIdentity::mostRelevantContract($contact);
        if ($contract instanceof Contract) {
            $bag['contract'] = [
                'id' => $contract->id,
                'balance_owed' => $contract->balanceOwed(),
                'currency' => $contract->currency,
            ];
        }

        $deal = Deal::query()
            ->where('contact_id', $contact->id)
            ->whereNotIn('status', DealStatus::terminalValues())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if ($deal instanceof Deal) {
            $bag['deal'] = [
                'id' => $deal->id,
                'status' => $deal->status instanceof \BackedEnum
                    ? $deal->status->value
                    : (string) $deal->status,
            ];
        }

        $bag['pay_link'] = '[pay-link]';

        return $bag;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forRun(AutomationRun $run): array
    {
        $bag = [];

        $contact = SubjectChain::contact($run);
        if ($contact instanceof Contact) {
            $bag['contact'] = [
                'id' => $contact->id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'name' => trim($contact->first_name.' '.$contact->last_name),
                'email' => $contact->email,
                'company' => $contact->company,
            ];
        }

        $contract = SubjectChain::contract($run);
        if ($contract instanceof Contract) {
            $bag['contract'] = [
                'id' => $contract->id,
                'balance_owed' => $contract->balanceOwed(),
                'currency' => $contract->currency,
            ];
        }

        if ($run->subject_type === 'deal' && $run->subject_id !== null) {
            $deal = Deal::query()->find($run->subject_id);
            if ($deal instanceof Deal) {
                $bag['deal'] = [
                    'id' => $deal->id,
                    'status' => $deal->status instanceof \BackedEnum
                        ? $deal->status->value
                        : (string) $deal->status,
                ];
            }
        }

        // S10 gap: real payment-request links land later; keep a stable placeholder.
        $bag['pay_link'] = '[pay-link]';

        return $bag;
    }
}
