<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\Contact;
use App\Models\Site;
use App\Models\WhatsappTemplate;
use RuntimeException;

/**
 * Locale ladder over approved WhatsApp template rows sharing a name:
 * contact.locale → site country language → en → any approved.
 */
final class WhatsAppTemplateResolver
{
    /**
     * @return array{
     *     template: WhatsappTemplate,
     *     preferred: string|null,
     *     chosen: string,
     *     used_fallback: bool
     * }
     */
    public static function resolve(
        int $accountId,
        string $name,
        Contact $contact,
        ?Site $site,
    ): array {
        $approved = WhatsappTemplate::query()
            ->where('communication_account_id', $accountId)
            ->where('name', $name)
            ->where('status', WhatsappTemplate::STATUS_APPROVED)
            ->get();

        if ($approved->isEmpty()) {
            throw new RuntimeException("No approved WhatsApp template named [{$name}].");
        }

        $byLocale = $approved->keyBy('language');

        $contactLocale = is_string($contact->locale) && $contact->locale !== ''
            ? $contact->locale
            : null;

        $preferred = $contactLocale ?? SiteLocale::for($site);

        if ($contactLocale !== null && $byLocale->has($contactLocale)) {
            /** @var WhatsappTemplate $template */
            $template = $byLocale->get($contactLocale);

            return [
                'template' => $template,
                'preferred' => $preferred,
                'chosen' => $template->language,
                'used_fallback' => false,
            ];
        }

        $siteLocale = SiteLocale::for($site);
        if ($byLocale->has($siteLocale)) {
            /** @var WhatsappTemplate $template */
            $template = $byLocale->get($siteLocale);

            return [
                'template' => $template,
                'preferred' => $preferred,
                'chosen' => $template->language,
                'used_fallback' => $contactLocale !== null && $contactLocale !== $template->language,
            ];
        }

        if ($byLocale->has('en')) {
            /** @var WhatsappTemplate $template */
            $template = $byLocale->get('en');

            return [
                'template' => $template,
                'preferred' => $preferred,
                'chosen' => $template->language,
                'used_fallback' => $preferred !== 'en',
            ];
        }

        /** @var WhatsappTemplate $any */
        $any = $approved->first();

        return [
            'template' => $any,
            'preferred' => $preferred,
            'chosen' => $any->language,
            'used_fallback' => true,
        ];
    }
}
