<?php

declare(strict_types=1);

use App\Http\Controllers\Facility\SiteController;
use App\Http\Controllers\Facility\UnitClassController;
use App\Http\Controllers\Facility\UnitClassRateController;
use App\Http\Controllers\Facility\UnitController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    Route::get('/', function () {
        return 'This is your multi-tenant application. The id of the current tenant is ' . tenant('id');
    });
});

Route::middleware([
    'api',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->prefix('api')->group(function () {
    Route::apiResource('sites', SiteController::class);
    Route::apiResource('sites.units', UnitController::class)->shallow();
    Route::apiResource('unit-classes', UnitClassController::class);
    Route::apiResource('unit-class-rates', UnitClassRateController::class)
        ->only(['index', 'store']);
});
