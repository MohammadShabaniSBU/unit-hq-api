<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Auth;

use App\Support\Auth\Permission;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PermissionEnumTest extends TestCase
{
    #[Test]
    public function values_are_unique_and_dotted(): void
    {
        $values = array_map(static fn (Permission $p): string => $p->value, Permission::cases());

        $this->assertSame(count($values), count(array_unique($values)));

        foreach (Permission::cases() as $permission) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/',
                $permission->value,
                "Permission {$permission->name} value must be dotted domain.action",
            );
            $this->assertSame(
                explode('.', $permission->value)[0],
                $permission->domain(),
            );
            $this->assertSame('permissions.'.$permission->value, $permission->i18nKey());
        }
    }
}
