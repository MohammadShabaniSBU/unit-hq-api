<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Models\Contact;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\SubscriptionList;
use App\Support\Communications\Channel;
use App\Support\Communications\ContactChannelMatcher;

/**
 * Legal footer for marketing-class sends (M8). Appended by the renderer, never
 * a template block, so sender identification (LSSI art. 20) cannot be removed.
 *
 * Email: legal identity from the site's legal entity, the "why am I receiving
 * this" line (list label), preferences and unsubscribe links.
 * SMS / WhatsApp: one short line "{trading_name}: baja {preferences url}".
 */
final class MarketingFooter
{
    /** @var list<string> */
    private const LOCALES = ['en', 'es', 'fr'];

    /**
     * @return array{html: string, text: string}
     */
    public static function render(
        Site $site,
        Contact $contact,
        Channel $channel,
        ?int $listId = null,
        ?string $address = null,
    ): array {
        $locale = self::locale($contact);
        $entity = $site->legalEntity;

        $address ??= $channel === Channel::Email ? (string) ($contact->email ?? '') : '';
        $normalized = ContactChannelMatcher::normalize($channel, $address);
        $token = $normalized !== '' && $contact->id > 0
            ? PreferenceToken::issue($contact->id, $channel, $normalized, $listId)
            : null;

        return $channel === Channel::Email
            ? self::email($entity, $contact, $token, $listId, $locale)
            : self::short($entity, $token, $locale);
    }

    /**
     * Append the email footer to rendered HTML and text.
     *
     * @param  array{html: string, text: string}  $footer
     * @return array{html: string, text: string}
     */
    public static function appendEmail(string $html, string $text, array $footer): array
    {
        if ($footer['html'] !== '') {
            $position = strripos($html, '</body>');
            $html = $position === false
                ? $html.$footer['html']
                : substr($html, 0, $position).$footer['html'].substr($html, $position);
        }

        if ($footer['text'] !== '') {
            $text = trim($text) === '' ? $footer['text'] : rtrim($text)."\n\n-- \n".$footer['text'];
        }

        return ['html' => $html, 'text' => $text];
    }

    /**
     * @return array{html: string, text: string}
     */
    private static function email(?LegalEntity $entity, Contact $contact, ?string $token, ?int $listId, string $locale): array
    {
        $identity = self::identityLine($entity);
        $why = self::whyLine($listId, $locale);

        $lines = [];
        $htmlLines = [];

        if ($identity !== '') {
            $lines[] = $identity;
            $htmlLines[] = e($identity);
        }

        $lines[] = $why;
        $htmlLines[] = e($why);

        if ($token !== null) {
            $preferences = PreferenceToken::pageUrl($token);
            $unsubscribe = PreferenceToken::unsubscribeUrlFromToken($token);
            $preferencesLabel = trans('marketing.footer.preferences', [], $locale);
            $unsubscribeLabel = trans('marketing.footer.unsubscribe', [], $locale);

            $lines[] = $preferencesLabel.': '.$preferences;
            $lines[] = $unsubscribeLabel.': '.$unsubscribe;
            $htmlLines[] = '<a href="'.e($preferences).'" style="color:#6b7280;">'.e($preferencesLabel).'</a>'
                .' · '
                .'<a href="'.e($unsubscribe).'" style="color:#6b7280;">'.e($unsubscribeLabel).'</a>';
        }

        $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" data-marketing-footer="1">'
            .'<tr><td align="center" style="padding:16px 12px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:#6b7280;">'
            .implode('<br />', $htmlLines)
            .'</td></tr></table>';

        return ['html' => $html, 'text' => implode("\n", $lines)];
    }

    /**
     * @return array{html: string, text: string}
     */
    private static function short(?LegalEntity $entity, ?string $token, string $locale): array
    {
        if ($token === null) {
            return ['html' => '', 'text' => ''];
        }

        $name = $entity !== null ? trim((string) ($entity->trading_name ?: $entity->legal_name)) : '';

        return [
            'html' => '',
            'text' => trans('marketing.footer.sms', [
                'name' => $name,
                'url' => PreferenceToken::pageUrl($token),
            ], $locale),
        ];
    }

    private static function identityLine(?LegalEntity $entity): string
    {
        if ($entity === null) {
            return '';
        }

        $address = implode(', ', array_filter([
            trim((string) $entity->address_line1),
            trim((string) ($entity->address_line2 ?? '')),
            trim(implode(' ', array_filter([
                trim((string) $entity->postal_code),
                trim((string) $entity->city),
            ], static fn (string $part): bool => $part !== ''))),
        ], static fn (string $part): bool => $part !== ''));

        return implode(' · ', array_filter([
            trim((string) $entity->legal_name),
            trim((string) $entity->tax_id),
            $address,
        ], static fn (string $part): bool => $part !== ''));
    }

    private static function whyLine(?int $listId, string $locale): string
    {
        $list = $listId !== null ? SubscriptionList::query()->find($listId) : null;
        $label = $list !== null ? self::listLabel($list, $locale) : '';

        return $label !== ''
            ? trans('marketing.footer.why', ['list' => $label], $locale)
            : trans('marketing.footer.why_generic', [], $locale);
    }

    private static function listLabel(SubscriptionList $list, string $locale): string
    {
        $labels = $list->labels;

        return trim((string) ($labels[$locale] ?? $labels['en'] ?? (is_array($labels) ? (reset($labels) ?: '') : '')));
    }

    private static function locale(Contact $contact): string
    {
        $locale = strtolower(substr(trim((string) ($contact->locale ?? '')), 0, 2));

        return in_array($locale, self::LOCALES, true) ? $locale : 'en';
    }
}
