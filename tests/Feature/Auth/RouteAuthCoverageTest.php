<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\PublicApiAllowlist;
use Tests\TestCase;

/**
 * Fail-closed gate: every api/* route is either behind auth:sanctum or on the
 * public allowlist. Keep this list in sync with the PUBLIC block in routes/api.php.
 */
class RouteAuthCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_route_is_authenticated_or_allowlisted(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            if ($this->hasSanctum($route->gatherMiddleware())) {
                continue;
            }

            if (PublicApiAllowlist::contains($uri)) {
                continue;
            }

            $offenders[] = implode('|', $route->methods()).' '.$uri;
        }

        $this->assertSame(
            [],
            $offenders,
            "API routes missing auth:sanctum and not on the public allowlist:\n".implode("\n", $offenders),
        );
    }

    public function test_allowlist_entries_still_exist(): void
    {
        $uris = collect(Route::getRoutes())
            ->map(fn ($route) => $route->uri())
            ->unique()
            ->all();

        $missing = array_values(array_filter(
            PublicApiAllowlist::URIS,
            fn (string $uri): bool => ! in_array($uri, $uris, true),
        ));

        $this->assertSame(
            [],
            $missing,
            'Allowlisted URIs no longer registered — remove from allowlist or restore the route: '.implode(', ', $missing),
        );
    }

    public function test_invented_allowlist_entry_is_rejected(): void
    {
        $uris = collect(Route::getRoutes())
            ->map(fn ($route) => $route->uri())
            ->unique()
            ->all();

        $this->assertNotContains(
            'api/definitely-not-a-real-route',
            $uris,
            'Sanity: invented URI must not exist so allowlist_entries_still_exist stays fail-closed.',
        );

        $this->assertFalse(
            PublicApiAllowlist::contains('api/definitely-not-a-real-route'),
        );
    }

    /**
     * @param  list<string>  $middleware
     */
    private function hasSanctum(array $middleware): bool
    {
        foreach ($middleware as $name) {
            if ($name === 'auth:sanctum') {
                return true;
            }
            if (str_contains($name, 'Authenticate:sanctum')) {
                return true;
            }
        }

        return false;
    }
}
