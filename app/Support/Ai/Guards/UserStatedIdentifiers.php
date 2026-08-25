<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Models\Unit;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Tools\EntityRef;

/**
 * Narrow licence grant from inbound user text. A loose parse is a hole.
 */
final class UserStatedIdentifiers
{
    /**
     * @return list<EntityRef>
     */
    public static function extract(string $body, ?int $siteId): array
    {
        $refs = [];

        if (preg_match_all('/\bsite(?:\s+with)?(?:\s+id)?\s+(\d+)\b/i', $body, $matches) > 0) {
            foreach ($matches[1] as $raw) {
                $id = (int) $raw;
                if ($id > 0) {
                    $refs[] = EntityRef::of(EntityType::Site, $id, 'site '.$id);
                }
            }
        }

        if (preg_match_all('/\bsite_id\s+(\d+)\b/i', $body, $matches) > 0) {
            foreach ($matches[1] as $raw) {
                $id = (int) $raw;
                if ($id > 0) {
                    $refs[] = EntityRef::of(EntityType::Site, $id, 'site '.$id);
                }
            }
        }

        if (preg_match_all('/\bunit[_\s-]?class(?:\s+with)?(?:\s+id)?\s+(\d+)\b/i', $body, $matches) > 0) {
            foreach ($matches[1] as $raw) {
                $id = (int) $raw;
                if ($id > 0) {
                    $refs[] = EntityRef::of(EntityType::UnitClass, $id, 'unit_class '.$id);
                }
            }
        }

        if (preg_match_all('/\bunit\s+([A-Za-z0-9][A-Za-z0-9-]*)\b/i', $body, $matches) > 0) {
            foreach ($matches[1] as $token) {
                if (ctype_digit($token) || strcasecmp($token, 'class') === 0) {
                    continue;
                }
                $ref = self::resolveUnit($token, $siteId);
                if ($ref !== null) {
                    $refs[] = $ref;
                }
            }
        }

        return $refs;
    }

    private static function resolveUnit(string $unitNumber, ?int $siteId): ?EntityRef
    {
        $query = Unit::query()->where('enabled', true)->where('unit_number', $unitNumber);
        if ($siteId !== null && $siteId > 0) {
            $query->where('site_id', $siteId);
        }

        $units = $query->get();
        if ($units->count() !== 1) {
            return null;
        }

        $unit = $units->first();

        return $unit instanceof Unit ? EntityRef::unit($unit) : null;
    }
}
