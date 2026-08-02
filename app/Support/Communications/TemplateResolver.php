<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\Contact;
use App\Models\Site;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use RuntimeException;

/**
 * Sole path for senders/renderers to obtain template content for a contact.
 * Locale ladder: contact.locale → site country language → en → any.
 */
final class TemplateResolver
{
    public static function variant(TemplateFamily $family, Contact $contact, ?Site $site): TemplateVariant
    {
        $family->loadMissing('variants');

        $variants = $family->variants;
        if ($variants->isEmpty()) {
            throw new RuntimeException("Template family [{$family->id}] has no variants.");
        }

        $byLocale = $variants->keyBy('locale');

        $contactLocale = is_string($contact->locale) && $contact->locale !== ''
            ? $contact->locale
            : null;

        if ($contactLocale !== null && $byLocale->has($contactLocale)) {
            return $byLocale->get($contactLocale);
        }

        $siteLocale = SiteLocale::for($site);
        if ($byLocale->has($siteLocale)) {
            return $byLocale->get($siteLocale);
        }

        if ($byLocale->has('en')) {
            return $byLocale->get('en');
        }

        /** @var TemplateVariant $any */
        $any = $variants->first();

        return $any;
    }
}
