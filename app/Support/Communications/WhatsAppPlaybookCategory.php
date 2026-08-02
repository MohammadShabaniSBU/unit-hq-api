<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Enums\PlaybookKind;
use App\Models\WhatsappTemplate;
use Illuminate\Validation\ValidationException;

/**
 * Debt/lead category gates for send_whatsapp_template (save-time + send-time).
 */
final class WhatsAppPlaybookCategory
{
    /**
     * @return list<string>
     */
    public static function allowedFor(PlaybookKind $kind): array
    {
        return match ($kind) {
            PlaybookKind::DebtProcess => ['utility'],
            PlaybookKind::LeadChase => ['marketing', 'utility'],
        };
    }

    public static function categoryForName(string $name): ?string
    {
        $row = WhatsappTemplate::query()
            ->where('name', $name)
            ->where('status', '!=', WhatsappTemplate::STATUS_ARCHIVED)
            ->orderByRaw("CASE status WHEN 'approved' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->first(['category']);

        return $row !== null ? (string) $row->category : null;
    }

    public static function assertAllowedAtSave(PlaybookKind $kind, string $templateName): void
    {
        $category = self::categoryForName($templateName);
        if ($category === null) {
            throw ValidationException::withMessages([
                'steps' => "WhatsApp template [{$templateName}] was not found in the registry.",
            ]);
        }

        self::assertCategory($kind, $category, $templateName);
    }

    public static function isAllowed(PlaybookKind $kind, string $category): bool
    {
        return in_array($category, self::allowedFor($kind), true);
    }

    /**
     * @throws ValidationException
     */
    public static function assertCategory(PlaybookKind $kind, string $category, string $templateName): void
    {
        if (self::isAllowed($kind, $category)) {
            return;
        }

        $allowed = implode('|', self::allowedFor($kind));

        throw ValidationException::withMessages([
            'steps' => "Playbook kind [{$kind->value}] may only use WhatsApp templates in categories [{$allowed}]; [{$templateName}] is [{$category}].",
        ]);
    }
}
