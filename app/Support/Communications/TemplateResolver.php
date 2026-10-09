<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\Contact;
use App\Models\Site;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Models\TemplateVersion;
use App\Support\Communications\Exceptions\TemplateNotPublished;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Sole path for senders/renderers to obtain template content for a contact.
 * Locale ladder: contact.locale → site country language → en → any.
 *
 * variant() is latest published only. variantOf() is a pinned version, including a draft.
 */
final class TemplateResolver
{
    public static function variant(TemplateFamily $family, Contact $contact, ?Site $site): TemplateVariant
    {
        $family->loadMissing('currentVersion');
        $version = $family->currentVersion;
        if ($version === null) {
            throw TemplateNotPublished::forFamily($family);
        }

        return self::variantOf($version, $contact, $site);
    }

    public static function variantOf(TemplateVersion $version, Contact $contact, ?Site $site): TemplateVariant
    {
        $version->loadMissing('variants');
        $variants = $version->variants;
        if ($variants->isEmpty()) {
            throw new RuntimeException("Template family [{$version->template_family_id}] has no variants.");
        }

        return self::pick($variants, $contact, $site);
    }

    public static function preferredLocale(Contact $contact, ?Site $site): string
    {
        return self::contactLocale($contact) ?? SiteLocale::for($site);
    }

    /**
     * @param  Collection<int, TemplateVariant>  $variants
     */
    private static function pick(Collection $variants, Contact $contact, ?Site $site): TemplateVariant
    {
        $byLocale = $variants->keyBy('locale');
        $contactLocale = self::contactLocale($contact);

        if ($contactLocale !== null && $byLocale->has($contactLocale)) {
            /** @var TemplateVariant $match */
            $match = $byLocale->get($contactLocale);

            return $match;
        }

        $siteLocale = SiteLocale::for($site);
        if ($byLocale->has($siteLocale)) {
            /** @var TemplateVariant $match */
            $match = $byLocale->get($siteLocale);

            return $match;
        }

        if ($byLocale->has('en')) {
            /** @var TemplateVariant $match */
            $match = $byLocale->get('en');

            return $match;
        }

        /** @var TemplateVariant $any */
        $any = $variants->first();

        return $any;
    }

    private static function contactLocale(Contact $contact): ?string
    {
        return is_string($contact->locale) && $contact->locale !== ''
            ? $contact->locale
            : null;
    }
}
