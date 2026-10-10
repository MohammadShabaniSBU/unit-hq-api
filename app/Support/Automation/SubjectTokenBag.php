<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\CampaignType;
use App\Enums\DealStatus;
use App\Enums\TemplatePurpose;
use App\Models\AutomationRun;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\SubscriptionList;
use App\Support\Communications\Channel;
use App\Support\Communications\ComposerIdentity;
use App\Support\Communications\ContactChannelMatcher;
use App\Support\Marketing\PreferenceToken;

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
            'contract.unit_name',
            'contract.unit_rate',
            'deal.id',
            'deal.status',
            'pay_link',
            'preferences_url',
            'unsubscribe_url',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function contractBag(Contract $contract): array
    {
        $contract->loadMissing(['unitItem.item', 'unitItem.price']);

        $unitName = null;
        $unitRate = null;
        $unitItem = $contract->unitItem;
        if ($unitItem !== null) {
            $item = $unitItem->item;
            if ($item !== null && isset($item->unit_number)) {
                $unitName = (string) $item->unit_number;
            }
            $unitRate = $unitItem->base_rate !== null
                ? (string) $unitItem->base_rate
                : ($unitItem->price?->amount !== null ? (string) $unitItem->price->amount : null);
        }

        return [
            'contract' => [
                'id' => $contract->id,
                'balance_owed' => $contract->balanceOwed(),
                'currency' => $contract->currency,
                'unit_name' => $unitName,
                'unit_rate' => $unitRate,
            ],
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
            $bag = array_replace_recursive($bag, self::contractBag($contract));
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

        return array_replace($bag, self::marketingUrls($contact));
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
            $bag = array_replace_recursive($bag, self::contractBag($contract));
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

        if ($contact instanceof Contact) {
            $bag = array_replace($bag, self::marketingUrls($contact));
        }

        return $bag;
    }

    /**
     * Publish-time stand-in for the real bags. One place to extend when tokens are added.
     *
     * @return array<string, mixed>
     */
    public static function sample(TemplatePurpose $purpose): array
    {
        $bag = [
            'contact' => [
                'id' => 1,
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
                'company' => 'Analytical Engines',
            ],
            'pay_link' => 'https://example.test/pay',
            'preferences_url' => 'https://example.test/preferences/sample',
            'unsubscribe_url' => 'https://example.test/unsubscribe/sample',
        ];

        if ($purpose === TemplatePurpose::Debt || $purpose === TemplatePurpose::Contract) {
            $bag['contract'] = [
                'id' => 1,
                'balance_owed' => '0.00',
                'currency' => 'EUR',
                'unit_name' => 'A1',
                'unit_rate' => '10.00',
            ];
        }

        if ($purpose === TemplatePurpose::Lead || $purpose === TemplatePurpose::Offer) {
            $bag['deal'] = [
                'id' => 1,
                'status' => 'open',
            ];
        }

        if ($purpose === TemplatePurpose::Newsletter) {
            $bag['newsletter'] = [
                'issue_number' => 1,
                'name' => 'Newsletter',
            ];
        }

        return $bag;
    }

    /**
     * Issue tokens for a newsletter send. Not part of forContact or forRun.
     *
     * @return array{newsletter: array{issue_number: int, name: string}}
     */
    public static function newsletter(SubscriptionList $list, int $issueNumber, string $locale): array
    {
        return [
            'newsletter' => [
                'issue_number' => $issueNumber,
                'name' => self::listLabel($list, $locale),
            ],
        ];
    }

    /**
     * Merge newsletter tokens onto a send bag. No-op for other campaign types.
     *
     * @param  array<string, mixed>  $bag
     * @return array<string, mixed>
     */
    public static function mergeNewsletter(array $bag, Campaign $campaign, string $locale): array
    {
        if ($campaign->type !== CampaignType::Newsletter) {
            return $bag;
        }

        $campaign->loadMissing('subscriptionList');
        $list = $campaign->subscriptionList;
        if (! $list instanceof SubscriptionList) {
            return $bag;
        }

        return array_replace($bag, self::newsletter(
            $list,
            (int) ($campaign->newsletter_issue_number ?? 0),
            $locale,
        ));
    }

    private static function listLabel(SubscriptionList $list, string $locale): string
    {
        $code = strtolower(substr(trim($locale), 0, 2));
        if (! in_array($code, ['en', 'es', 'fr'], true)) {
            $code = 'en';
        }

        $labels = $list->labels;
        $label = trim((string) ($labels[$code] ?? $labels['en'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        $first = reset($labels);
        if (is_string($first) && trim($first) !== '') {
            return trim($first);
        }

        return $list->key;
    }

    /**
     * Preference and unsubscribe links for this recipient. Absent when there is no email.
     *
     * @return array<string, string>
     */
    private static function marketingUrls(Contact $contact): array
    {
        $email = ContactChannelMatcher::normalizeEmail((string) ($contact->email ?? ''));
        if ($email === '') {
            return [];
        }

        return PreferenceToken::templateUrls($contact->id, Channel::Email, $email);
    }
}
