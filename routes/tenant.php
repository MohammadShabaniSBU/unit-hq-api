<?php

declare(strict_types=1);

use App\Http\Controllers;
use App\Http\Controllers\Facility;
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

    Route::get('settings/general', [Facility\SettingController::class, 'showGeneral']);
    Route::patch('settings/general', [Facility\SettingController::class, 'updateGeneral']);
    Route::get('settings/billing', [Facility\SettingController::class, 'showBilling']);
    Route::patch('settings/billing', [Facility\SettingController::class, 'updateBilling']);

    Route::get('countries/options', [Facility\CountryController::class, 'options']);
    Route::get('sites/options', [Facility\SiteController::class, 'options']);
    Route::get('unit-classes/options', [Facility\UnitClassController::class, 'options']);
    Route::get('units/options', [Facility\UnitController::class, 'options']);
    Route::get('insurances/options', [Controllers\InsuranceController::class, 'options']);

    Route::apiResource('sites', Facility\SiteController::class);
    Route::get('sites/{site}/maps', [Facility\SiteMapController::class, 'index']);
    Route::post('sites/{site}/maps', [Facility\SiteMapController::class, 'store']);
    Route::get('site-maps/{siteMap}', [Facility\SiteMapController::class, 'show']);
    Route::patch('site-maps/{siteMap}', [Facility\SiteMapController::class, 'update']);
    Route::delete('site-maps/{siteMap}', [Facility\SiteMapController::class, 'destroy']);
    Route::apiResource('units', Facility\UnitController::class);

    Route::get('contacts/options', [Controllers\ContactController::class, 'options']);
    Route::apiResource('contacts', Controllers\ContactController::class);
    Route::post('contacts/{contact}/channels', [Controllers\ContactChannelController::class, 'store']);
    Route::patch('contacts/{contact}/channels/{channel}', [Controllers\ContactChannelController::class, 'update']);
    Route::delete('contacts/{contact}/channels/{channel}', [Controllers\ContactChannelController::class, 'destroy']);
    Route::post('contacts/{contact}/addresses', [Controllers\ContactAddressController::class, 'store']);
    Route::patch('contacts/{contact}/addresses/{address}', [Controllers\ContactAddressController::class, 'update']);
    Route::delete('contacts/{contact}/addresses/{address}', [Controllers\ContactAddressController::class, 'destroy']);
    Route::post('contacts/{contact}/tasks', [Controllers\ContactTaskController::class, 'store']);
    Route::patch('contacts/{contact}/tasks/{task}', [Controllers\ContactTaskController::class, 'update']);

    Route::post('notes', [Controllers\NoteController::class, 'store']);

    Route::get('copilot/conversations', [Controllers\CopilotController::class, 'index']);
    Route::post('copilot/conversations', [Controllers\CopilotController::class, 'store']);
    Route::get('copilot/conversations/{id}', [Controllers\CopilotController::class, 'show']);
    Route::delete('copilot/conversations/{id}', [Controllers\CopilotController::class, 'destroy']);
    Route::put('copilot/conversations/{id}/messages', [Controllers\CopilotController::class, 'syncMessages']);
    Route::post('copilot/chat', [Controllers\CopilotController::class, 'chat']);

    Route::get('deals/options', [Controllers\DealController::class, 'options']);
    Route::apiResource('deals', Controllers\DealController::class);

    Route::apiResource('offers', Controllers\OfferController::class);

    Route::post('offer-options', [Controllers\OfferOptionController::class, 'store']);
    Route::patch('offer-options/{offerOption}', [Controllers\OfferOptionController::class, 'update']);
    Route::delete('offer-options/{offerOption}', [Controllers\OfferOptionController::class, 'destroy']);

    Route::post('reservations/{reservation}/convert', [Controllers\ReservationController::class, 'convert']);
    Route::apiResource('reservations', Controllers\ReservationController::class);

    Route::apiResource('contracts', Controllers\ContractController::class);

    Route::get('unit-class-price-matrix', [Facility\UnitClassPriceMatrixController::class, 'index']);
    Route::get('unit-class-occupancy-matrix', [Facility\UnitClassOccupancyMatrixController::class, 'index']);
    Route::get('unit-classes/{unitClass}/prices', [Facility\UnitClassPriceController::class, 'index']);
    Route::post('unit-classes/{unitClass}/prices', [Facility\UnitClassPriceController::class, 'store']);

    Route::apiResource('unit-classes', Facility\UnitClassController::class);

    Route::apiResource('unit-class-rates', Facility\UnitClassRateController::class)->only(['index', 'store']);
});
