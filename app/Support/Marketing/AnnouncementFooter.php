<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Models\Site;

/**
 * Service footer for announcement sends. Site name and phone only:
 * no unsubscribe link, no marketing preferences.
 */
final class AnnouncementFooter
{
    /**
     * @return array{html: string, text: string}
     */
    public static function render(Site $site): array
    {
        $name = trim($site->name);
        $phone = trim((string) ($site->contact_phone ?? ''));
        $text = $phone === ''
            ? $name
            : (string) trans('marketing.footer.announcement', [
                'name' => $name,
                'phone' => $phone,
            ]);

        if ($text === '') {
            return ['html' => '', 'text' => ''];
        }

        return [
            'html' => '<p data-announcement-footer="1">'.e($text).'</p>',
            'text' => $text,
        ];
    }
}
