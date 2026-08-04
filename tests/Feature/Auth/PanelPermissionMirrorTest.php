<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Support\Auth\Permission;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fail-closed: panel app/types/permissions.ts must match Permission::cases()
 * in both directions.
 */
class PanelPermissionMirrorTest extends TestCase
{
    #[Test]
    public function php_and_ts_enums_match(): void
    {
        $path = $this->panelPermissionsPath();
        $this->assertFileExists(
            $path,
            "Panel permissions.ts not found at {$path}. "
            .'Set UNIT_HQ_PANEL_PATH or mount ../unit-hq-panel in docker-compose.test.yml.',
        );

        $tsValues = $this->parseTsPermissionValues((string) file_get_contents($path));
        $phpValues = array_map(
            static fn (Permission $p): string => $p->value,
            Permission::cases(),
        );
        sort($tsValues);
        sort($phpValues);

        $missingInTs = array_values(array_diff($phpValues, $tsValues));
        $extraInTs = array_values(array_diff($tsValues, $phpValues));

        $this->assertSame(
            [],
            $missingInTs,
            "Permission values in PHP but missing from panel permissions.ts:\n".implode("\n", $missingInTs),
        );
        $this->assertSame(
            [],
            $extraInTs,
            "Permission values in panel permissions.ts but missing from PHP enum:\n".implode("\n", $extraInTs),
        );
    }

    private function panelPermissionsPath(): string
    {
        $root = env('UNIT_HQ_PANEL_PATH')
            ?: getenv('UNIT_HQ_PANEL_PATH')
            ?: null;

        if (is_string($root) && $root !== '') {
            return rtrim($root, '/').'/app/types/permissions.ts';
        }

        return base_path('../unit-hq-panel/app/types/permissions.ts');
    }

    /**
     * @return list<string>
     */
    private function parseTsPermissionValues(string $contents): array
    {
        if (! preg_match('/export enum Permission\s*\{([^}]+)\}/s', $contents, $enumMatch)) {
            $this->fail('Could not find `export enum Permission` in permissions.ts');
        }

        preg_match_all("/=\s*'([^']+)'/", $enumMatch[1], $valueMatches);

        return array_values($valueMatches[1]);
    }
}
