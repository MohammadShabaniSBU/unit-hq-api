<?php

declare(strict_types=1);

use App\Http\Controllers\Facility\SettingController;
use App\Http\Controllers\Facility\SiteController;
use App\Http\Controllers\Facility\UnitClassController;
use App\Http\Controllers\Facility\UnitClassPriceController;
use App\Http\Controllers\Facility\UnitClassPriceMatrixController;
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

    Route::get('settings/general', [SettingController::class, 'showGeneral']);
    Route::patch('settings/general', [SettingController::class, 'updateGeneral']);
    Route::get('settings/billing', [SettingController::class, 'showBilling']);
    Route::patch('settings/billing', [SettingController::class, 'updateBilling']);

    Route::get('sites/options', [SiteController::class, 'options']);
    Route::get('unit-classes/options', [UnitClassController::class, 'options']);
    Route::apiResource('sites', SiteController::class);
    Route::apiResource('units', UnitController::class);
    Route::get('unit-class-price-matrix', [UnitClassPriceMatrixController::class, 'index']);
    Route::get('unit-classes/{unitClass}/prices', [UnitClassPriceController::class, 'index']);
    Route::post('unit-classes/{unitClass}/prices', [UnitClassPriceController::class, 'store']);
    Route::apiResource('unit-classes', UnitClassController::class);
    Route::apiResource('unit-class-rates', UnitClassRateController::class)
        ->only(['index', 'store']);
});
