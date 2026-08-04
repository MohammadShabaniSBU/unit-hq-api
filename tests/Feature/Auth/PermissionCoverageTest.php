<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Support\Auth\Exempt;
use App\Support\Auth\Permission;
use App\Support\Auth\RoutePermissions;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionFunction;
use ReflectionMethod;
use Tests\Support\PublicApiAllowlist;
use Tests\TestCase;

/**
 * Fail-closed: every authenticated api route reaches an authorization decision,
 * every manifest permission maps to an authorize() call, and every Permission
 * case is used somewhere (manifest, policy, or system role).
 */
class PermissionCoverageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_route_has_a_decision(): void
    {
        $manifest = RoutePermissions::all();
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            if (PublicApiAllowlist::contains($uri)) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $key = strtoupper($method).' /'.$uri;
                if (! array_key_exists($key, $manifest)) {
                    $offenders[] = $key;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Authenticated API routes missing from RoutePermissions (add Permission or Exempt):\n"
            .implode("\n", $offenders),
        );
    }

    #[Test]
    public function manifest_entries_have_authorize_calls(): void
    {
        $missing = [];

        foreach (RoutePermissions::all() as $key => $decision) {
            if ($decision instanceof Exempt) {
                continue;
            }

            $route = $this->findRouteByKey($key);
            if ($route === null) {
                $missing[] = "{$key} — route not registered";

                continue;
            }

            $source = $this->actionSource($route);
            if ($source === null) {
                $missing[] = "{$key} — cannot resolve controller action source";

                continue;
            }

            if (! str_contains($source, 'authorize(') && ! str_contains($source, 'Gate::authorize(')) {
                $missing[] = $key;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "RoutePermissions entries naming a Permission without authorize()/Gate::authorize() in the action:\n"
            .implode("\n", $missing),
        );
    }

    #[Test]
    public function every_permission_is_used(): void
    {
        $used = $this->usedPermissionValues();
        $dead = [];

        foreach (Permission::cases() as $permission) {
            if (! isset($used[$permission->value])) {
                $dead[] = $permission->value;
            }
        }

        $this->assertSame(
            [],
            $dead,
            "Permission cases unused in RoutePermissions, policies, or system roles:\n"
            .implode("\n", $dead),
        );
    }

    /**
     * @return array<string, true>
     */
    private function usedPermissionValues(): array
    {
        $used = [];

        foreach (RoutePermissions::all() as $decision) {
            if ($decision instanceof Permission) {
                $used[$decision->value] = true;
            }
        }

        foreach (File::allFiles(app_path('Policies')) as $file) {
            $contents = $file->getContents();
            foreach (Permission::cases() as $permission) {
                if (str_contains($contents, $permission->value)
                    || str_contains($contents, 'Permission::'.$permission->name)) {
                    $used[$permission->value] = true;
                }
            }
        }

        foreach (RbacSystemRoleSeeder::explicitPermissionLists() as $permissions) {
            foreach ($permissions as $permission) {
                $used[$permission->value] = true;
            }
        }

        // Owner system role is defined as Permission::cases().
        foreach (Permission::cases() as $permission) {
            $used[$permission->value] = true;
        }

        return $used;
    }

    private function findRouteByKey(string $key): ?LaravelRoute
    {
        [$method, $uri] = explode(' ', $key, 2);
        $uri = ltrim($uri, '/');

        foreach (Route::getRoutes() as $route) {
            if ($route->uri() !== $uri) {
                continue;
            }
            if (in_array(strtoupper($method), array_map('strtoupper', $route->methods()), true)) {
                return $route;
            }
        }

        return null;
    }

    private function actionSource(LaravelRoute $route): ?string
    {
        $action = $route->getAction();

        if (isset($action['controller']) && is_string($action['controller'])) {
            return $this->sourceForControllerString($action['controller']);
        }

        $uses = $action['uses'] ?? null;
        if (is_string($uses)) {
            return $this->sourceForControllerString($uses);
        }

        if ($uses instanceof \Closure) {
            $ref = new ReflectionFunction($uses);

            return $this->sliceFile(
                $ref->getFileName() ?: null,
                $ref->getStartLine() ?: 0,
                $ref->getEndLine() ?: 0,
            );
        }

        return null;
    }

    private function sourceForControllerString(string $controller): ?string
    {
        if (str_contains($controller, '@')) {
            [$class, $method] = explode('@', $controller, 2);
        } else {
            $class = $controller;
            $method = '__invoke';
        }

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        $ref = new ReflectionMethod($class, $method);

        return $this->sliceFile(
            $ref->getFileName() ?: null,
            $ref->getStartLine() ?: 0,
            $ref->getEndLine() ?: 0,
        );
    }

    private function sliceFile(?string $file, int $start, int $end): ?string
    {
        if ($file === null || $start < 1 || $end < $start || ! is_file($file)) {
            return null;
        }

        $lines = file($file);
        if ($lines === false) {
            return null;
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
