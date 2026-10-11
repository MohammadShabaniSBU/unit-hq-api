<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Models\Contact;
use App\Models\SignupForm;
use App\Models\SubscriptionList;

/**
 * Grants ads consent on the system advertising list. The only form path.
 */
final class AdvertisingConsent
{
    public const LIST_KEY = 'advertising';

    public const TEXT_KEY = 'advertising';

    public static function list(): ?SubscriptionList
    {
        return SubscriptionList::query()->where('key', self::LIST_KEY)->first();
    }

    /**
     * @param  array{ad_storage: bool, ad_user_data: bool, ad_personalization: bool}|null  $snapshot
     * @param  array<string, mixed>  $evidence
     */
    public static function recordFromSubmission(
        Contact $contact,
        SignupForm $form,
        ?array $snapshot,
        string $locale,
        array $evidence,
    ): void {
        if ($snapshot === null || $snapshot['ad_user_data'] !== true) {
            return;
        }

        $list = self::list();
        if ($list === null || $list->kind !== SubscriptionListKind::Advertising) {
            return;
        }

        if (ConsentState::isGranted($contact->id, ConsentChannel::Ads, $list->id)) {
            return;
        }

        $text = SignupSubmission::consentText(self::TEXT_KEY, $locale);
        if ($text === null) {
            return;
        }

        ConsentWriter::grant(
            $contact,
            ConsentChannel::Ads,
            $list,
            ConsentSource::Form,
            $text->id,
            [
                'ip' => (string) ($evidence['ip'] ?? ''),
                'user_agent' => (string) ($evidence['user_agent'] ?? ''),
                'form_id' => $form->id,
                'consent_snapshot' => $snapshot,
                'consent_text_version' => $text->version,
            ],
        );
    }
}
