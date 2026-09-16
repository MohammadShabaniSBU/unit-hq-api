<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Country\CountryProfiles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RejectCountryMismatch
{
    public function handle(Request $request, Closure $next): Response
    {
        if (CountryProfiles::isLockedMismatch()) {
            return response()->json([
                'message' => 'deployment_country_mismatch',
                'data' => [
                    'configured' => config('deployment.country'),
                    'locked' => CountryProfiles::identity()?->country_code,
                ],
            ], 503);
        }

        return $next($request);
    }
}
