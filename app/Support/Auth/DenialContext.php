<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * Request-scoped denial details for the 403 JSON shape.
 * Policies set this before returning false; the exception renderer pulls it.
 */
final class DenialContext
{
    private static ?Permission $permission = null;

    private static ?int $siteId = null;

    public static function set(Permission $permission, ?int $siteId = null): void
    {
        self::$permission = $permission;
        self::$siteId = $siteId;
    }

    /**
     * @return array{permission: string, site_id?: int}|null
     */
    public static function pull(): ?array
    {
        if (self::$permission === null) {
            return null;
        }

        $data = ['permission' => self::$permission->value];
        if (self::$siteId !== null) {
            $data['site_id'] = self::$siteId;
        }

        self::$permission = null;
        self::$siteId = null;

        return $data;
    }

    public static function clear(): void
    {
        self::$permission = null;
        self::$siteId = null;
    }
}
