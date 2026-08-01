<?php

declare(strict_types=1);

use App\Http\Controllers;
use App\Http\Controllers\Facility;
use App\Http\Controllers\Webhooks;
use Illuminate\Support\Facades\Route;

Route::post('login', [Controllers\EmployeeAuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('logout', [Controllers\EmployeeAuthController::class, 'logout']);
    Route::get('user', [Controllers\EmployeeAuthController::class, 'me']);
    Route::get('contacts/{contact}/interactions', [Controllers\ContactInteractionController::class, 'index']);
    Route::post('contacts/{contact}/interactions', [Controllers\ContactInteractionController::class, 'store']);

    // Credential-bearing routes — always behind auth (09-conventions-and-invariants.md #26/#27).
    Route::get('settings/communications', [Facility\CommunicationAccountController::class, 'index']);
    Route::put('settings/communications/{channel}', [Facility\CommunicationAccountController::class, 'update']);
    Route::post('settings/communications/{channel}/webhook', [Facility\CommunicationAccountController::class, 'createWebhook']);
    Route::delete('settings/communications/{channel}/webhook', [Facility\CommunicationAccountController::class, 'deleteWebhook']);
    Route::delete('settings/communications/{channel}/{provider}', [Facility\CommunicationAccountController::class, 'destroy']);

    Route::get('sites/{site}/stripe-settings', [Facility\SiteStripeSettingController::class, 'show']);
    Route::put('sites/{site}/stripe-settings', [Facility\SiteStripeSettingController::class, 'update']);
    Route::post('sites/{site}/stripe-settings/webhook', [Facility\SiteStripeSettingController::class, 'createWebhook']);
    Route::delete('sites/{site}/stripe-settings', [Facility\SiteStripeSettingController::class, 'destroy']);

    // Sender identities carry no secrets, but `update` authorizes via
    // SiteAccess::canManageSite($request->user(), ...) which needs an
    // authenticated request to resolve — must live behind auth:sanctum too.
    Route::get('sites/{site}/sender-identities', [Facility\SiteSenderIdentityController::class, 'index']);
    Route::put('sites/{site}/sender-identities/{channel}', [Facility\SiteSenderIdentityController::class, 'update']);

    // Billing runs — any authenticated Employee until S17 RBAC (10-open-decisions.md).
    Route::get('billing-runs', [Controllers\BillingRunController::class, 'index']);
    Route::get('billing-runs/{billingRun}', [Controllers\BillingRunController::class, 'show']);
    Route::post('billing-runs', [Controllers\BillingRunController::class, 'store']);
});

// Public inbound webhooks — authenticated by URL token / provider signature, not Sanctum.
Route::post('webhooks/stripe/{webhookRouteToken}', Webhooks\StripeWebhookController::class);
Route::post('webhooks/{provider}/{webhookUrlToken}', Webhooks\DeliveryWebhookController::class);

Route::get('settings/general', [Facility\SettingController::class, 'showGeneral']);
Route::patch('settings/general', [Facility\SettingController::class, 'updateGeneral']);
Route::get('settings/billing', [Facility\SettingController::class, 'showBilling']);
Route::patch('settings/billing', [Facility\SettingController::class, 'updateBilling']);
Route::get('settings/leasing', [Facility\SettingController::class, 'showLeasing']);
Route::patch('settings/leasing', [Facility\SettingController::class, 'updateLeasing']);
Route::get('settings/activity-log', [Facility\SettingController::class, 'showActivityLog']);
Route::patch('settings/activity-log', [Facility\SettingController::class, 'updateActivityLog']);

Route::get('activities', [Controllers\ActivityController::class, 'index']);

Route::get('countries/options', [Facility\CountryController::class, 'options']);
Route::get('legal-entities/options', [Controllers\LegalEntityController::class, 'options']);
Route::get('sites/options', [Facility\SiteController::class, 'options']);
Route::get('unit-classes/options', [Facility\UnitClassController::class, 'options']);
Route::get('units/options', [Facility\UnitController::class, 'options']);
Route::get('insurances/options', [Controllers\InsuranceController::class, 'options']);
Route::get('insurance-rate-matrix', [Facility\InsurancePriceMatrixController::class, 'index']);
Route::post('insurances/{insurance}/rates', [Facility\InsuranceRateController::class, 'store']);
Route::apiResource('insurances', Facility\InsurancePlanController::class)->only(['index', 'store', 'update']);
Route::apiResource('discounts', Controllers\DiscountController::class);

Route::get('tax-rates/options', [Controllers\TaxRateController::class, 'options']);
Route::get('tax-rates', [Controllers\TaxRateController::class, 'index']);
Route::post('tax-rates', [Controllers\TaxRateController::class, 'store']);
Route::patch('tax-rates/{taxRate}', [Controllers\TaxRateController::class, 'update']);
Route::post('tax-rates/{taxRate}/default', [Controllers\TaxRateController::class, 'setDefault']);

Route::get('attribute-definitions', [Controllers\AttributeDefinitionController::class, 'index']);
Route::post('attribute-definitions', [Controllers\AttributeDefinitionController::class, 'store']);
Route::get('attribute-definitions/{attributeDefinition}', [Controllers\AttributeDefinitionController::class, 'show']);
Route::patch('attribute-definitions/{attributeDefinition}', [Controllers\AttributeDefinitionController::class, 'update']);
Route::post('attribute-definitions/{attributeDefinition}/archive', [Controllers\AttributeDefinitionController::class, 'archive']);
Route::post('attribute-definitions/{attributeDefinition}/unarchive', [Controllers\AttributeDefinitionController::class, 'unarchive']);

Route::get('settings/object-customization/{entityType}', [Controllers\ObjectCustomizationController::class, 'show']);
Route::post('settings/object-customization/{entityType}/groups', [Controllers\ObjectCustomizationController::class, 'storeGroup']);
Route::post('settings/object-customization/{entityType}/groups/reorder', [Controllers\ObjectCustomizationController::class, 'reorderGroups']);
Route::patch('settings/object-customization/groups/{group}', [Controllers\ObjectCustomizationController::class, 'updateGroup']);
Route::delete('settings/object-customization/groups/{group}', [Controllers\ObjectCustomizationController::class, 'destroyGroup']);
Route::post('settings/object-customization/groups/{group}/fields', [Controllers\ObjectCustomizationController::class, 'storeField']);
Route::post('settings/object-customization/groups/{group}/fields/reorder', [Controllers\ObjectCustomizationController::class, 'reorderFields']);
Route::patch('settings/object-customization/fields/{field}', [Controllers\ObjectCustomizationController::class, 'updateField']);
Route::delete('settings/object-customization/fields/{field}', [Controllers\ObjectCustomizationController::class, 'destroyField']);

Route::get('{entityType}/{entityId}/attribute-values', [Controllers\AttributeValueController::class, 'index'])
    ->whereIn('entityType', ['contact', 'deal', 'offer', 'reservation', 'unit', 'contract'])
    ->whereNumber('entityId');
Route::patch('attribute-values', [Controllers\AttributeValueController::class, 'upsert']);

Route::apiResource('legal-entities', Controllers\LegalEntityController::class);
Route::post('legal-entities/{legal_entity}/archive', [Controllers\LegalEntityController::class, 'archive']);
Route::post('legal-entities/{legal_entity}/unarchive', [Controllers\LegalEntityController::class, 'unarchive']);
Route::get('legal-entities/{legal_entity}/invoice-series', [Controllers\InvoiceSeriesController::class, 'index']);
Route::post('legal-entities/{legal_entity}/invoice-series', [Controllers\InvoiceSeriesController::class, 'store']);
Route::patch('invoice-series/{invoice_series}', [Controllers\InvoiceSeriesController::class, 'update']);
Route::post('invoice-series/{invoice_series}/archive', [Controllers\InvoiceSeriesController::class, 'archive']);
Route::post('invoice-series/{invoice_series}/unarchive', [Controllers\InvoiceSeriesController::class, 'unarchive']);

Route::apiResource('sites', Facility\SiteController::class);
Route::post('sites/{site}/archive', [Facility\SiteController::class, 'archive']);
Route::post('sites/{site}/unarchive', [Facility\SiteController::class, 'unarchive']);
Route::get('sites/{site}/stripe/public-key', [Facility\SiteStripePublicKeyController::class, 'show']);
Route::get('sites/{site}/maps', [Facility\SiteMapController::class, 'index']);
Route::post('sites/{site}/maps', [Facility\SiteMapController::class, 'store']);
Route::post('sites/{site}/maps/validate', [Facility\SiteMapController::class, 'validateSvg']);
Route::get('site-maps/{siteMap}', [Facility\SiteMapController::class, 'show']);
Route::patch('site-maps/{siteMap}', [Facility\SiteMapController::class, 'update']);
Route::delete('site-maps/{siteMap}', [Facility\SiteMapController::class, 'destroy']);
Route::get('units/filters/schema', [Facility\UnitController::class, 'filterSchema']);
Route::post('units/search', [Facility\UnitController::class, 'search']);
Route::apiResource('units', Facility\UnitController::class);
Route::get('units/{unit}/holds', [Facility\UnitHoldController::class, 'index']);
Route::post('units/{unit}/holds', [Facility\UnitHoldController::class, 'store']);
Route::delete('units/{unit}/holds/{hold}', [Facility\UnitHoldController::class, 'destroy']);
Route::get('units/{unit}/occupancies', [Facility\UnitOccupancyController::class, 'index']);

Route::get('contacts/options', [Controllers\ContactController::class, 'options']);
Route::get('contacts/filters/schema', [Controllers\ContactController::class, 'filterSchema']);
Route::post('contacts/search', [Controllers\ContactController::class, 'search']);
Route::get('contacts/board', [Controllers\ContactBoardController::class, 'index']);
Route::get('contacts/board/columns/{status}', [Controllers\ContactBoardController::class, 'column']);
Route::patch('contacts/{contact}/status', [Controllers\ContactController::class, 'updateStatus']);
Route::apiResource('contacts', Controllers\ContactController::class);
Route::get('contacts/{contact}/transactions', [Controllers\ContactController::class, 'transactions']);
Route::post('contacts/{contact}/channels', [Controllers\ContactChannelController::class, 'store']);
Route::patch('contacts/{contact}/channels/{channel}', [Controllers\ContactChannelController::class, 'update']);
Route::delete('contacts/{contact}/channels/{channel}', [Controllers\ContactChannelController::class, 'destroy']);
Route::post('contacts/{contact}/addresses', [Controllers\ContactAddressController::class, 'store']);
Route::patch('contacts/{contact}/addresses/{address}', [Controllers\ContactAddressController::class, 'update']);
Route::delete('contacts/{contact}/addresses/{address}', [Controllers\ContactAddressController::class, 'destroy']);
Route::post('contacts/{contact}/tasks', [Controllers\ContactTaskController::class, 'store']);
Route::patch('contacts/{contact}/tasks/{task}', [Controllers\ContactTaskController::class, 'update']);

Route::get('tasks/board', [Controllers\TaskBoardController::class, 'index']);
Route::get('tasks/board/columns/{status}', [Controllers\TaskBoardController::class, 'column']);
Route::get('tasks', [Controllers\TaskController::class, 'index']);
Route::patch('tasks/{task}/status', [Controllers\TaskController::class, 'updateStatus']);

Route::post('notes', [Controllers\NoteController::class, 'store']);

Route::get('copilot/conversations', [Controllers\CopilotController::class, 'index']);
Route::post('copilot/conversations', [Controllers\CopilotController::class, 'store']);
Route::get('copilot/conversations/{id}', [Controllers\CopilotController::class, 'show']);
Route::delete('copilot/conversations/{id}', [Controllers\CopilotController::class, 'destroy']);
Route::put('copilot/conversations/{id}/messages', [Controllers\CopilotController::class, 'syncMessages']);
Route::post('copilot/chat', [Controllers\CopilotController::class, 'chat']);

Route::get('deals/options', [Controllers\DealController::class, 'options']);
Route::get('deals/filters/schema', [Controllers\DealController::class, 'filterSchema']);
Route::post('deals/search', [Controllers\DealController::class, 'search']);
Route::get('deals/board', [Controllers\DealBoardController::class, 'index']);
Route::get('deals/board/columns/{status}', [Controllers\DealBoardController::class, 'column']);
Route::patch('deals/{deal}/status', [Controllers\DealController::class, 'updateStatus']);
Route::post('deals/{deal}/tasks', [Controllers\DealTaskController::class, 'store']);
Route::patch('deals/{deal}/tasks/{task}', [Controllers\DealTaskController::class, 'update']);
Route::apiResource('deals', Controllers\DealController::class);

Route::get('offers/token/{token}', [Controllers\OfferController::class, 'showByToken']);
Route::get('offers/filters/schema', [Controllers\OfferController::class, 'filterSchema']);
Route::post('offers/search', [Controllers\OfferController::class, 'search']);
Route::get('offers/board', [Controllers\OfferBoardController::class, 'index']);
Route::get('offers/board/columns/{status}', [Controllers\OfferBoardController::class, 'column']);
Route::patch('offers/{offer}/status', [Controllers\OfferController::class, 'updateStatus']);
Route::apiResource('offers', Controllers\OfferController::class);

Route::post('offer-options', [Controllers\OfferOptionController::class, 'store']);
Route::patch('offer-options/{offerOption}', [Controllers\OfferOptionController::class, 'update']);
Route::delete('offer-options/{offerOption}', [Controllers\OfferOptionController::class, 'destroy']);
Route::post('offer-options/{offerOption}/select', [Controllers\OfferOptionController::class, 'select']);

Route::get('reservations/filters/schema', [Controllers\ReservationController::class, 'filterSchema']);
Route::post('reservations/search', [Controllers\ReservationController::class, 'search']);
Route::get('reservations/board', [Controllers\ReservationBoardController::class, 'index']);
Route::get('reservations/board/columns/{status}', [Controllers\ReservationBoardController::class, 'column']);
Route::patch('reservations/{reservation}/status', [Controllers\ReservationController::class, 'updateStatus']);
Route::get('reservations/{reservation}/convert-preview', [Controllers\ReservationController::class, 'convertPreview']);
Route::post('reservations/{reservation}/convert', [Controllers\ReservationController::class, 'convert']);
Route::apiResource('reservations', Controllers\ReservationController::class);

Route::get('contracts/filters/schema', [Controllers\ContractController::class, 'filterSchema']);
Route::post('contracts/search', [Controllers\ContractController::class, 'search']);
Route::get('contracts/board', [Controllers\ContractBoardController::class, 'index']);
Route::get('contracts/board/columns/{status}', [Controllers\ContractBoardController::class, 'column']);
Route::post('contracts/{contract}/notice', [Controllers\ContractController::class, 'notice']);
Route::post('contracts/{contract}/notice-withdraw', [Controllers\ContractController::class, 'noticeWithdraw']);
Route::post('contracts/{contract}/vacate-preview', [Controllers\ContractController::class, 'vacatePreview']);
Route::post('contracts/{contract}/vacate', [Controllers\ContractController::class, 'vacate']);
Route::post('contracts/{contract}/transfer-preview', [Controllers\ContractController::class, 'transferPreview']);
Route::post('contracts/{contract}/transfer', [Controllers\ContractController::class, 'transfer']);
Route::post('contracts/{contract}/invoices', [Controllers\InvoiceController::class, 'storeForContract']);
Route::post('contracts/{contract}/payments', [Controllers\PaymentController::class, 'store']);
Route::get('contracts/{contract}/next-bill', [Controllers\ContractController::class, 'nextBill']);
Route::apiResource('contracts', Controllers\ContractController::class);

Route::post('payments/{payment}/reverse', [Controllers\PaymentController::class, 'reverse']);

Route::get('invoices', [Controllers\InvoiceController::class, 'index']);
Route::get('invoices/{invoice}', [Controllers\InvoiceController::class, 'show']);
Route::get('invoices/{invoice}/pdf', [Controllers\InvoiceController::class, 'pdf']);
Route::post('invoices/{invoice}/rectify', [Controllers\InvoiceController::class, 'rectify']);

Route::get('unit-class-price-matrix', [Facility\UnitClassPriceMatrixController::class, 'index']);
Route::get('unit-class-occupancy-matrix', [Facility\UnitClassOccupancyMatrixController::class, 'index']);
Route::get('unit-classes/{unitClass}/prices', [Facility\UnitClassPriceController::class, 'index']);
Route::post('unit-classes/{unitClass}/prices', [Facility\UnitClassPriceController::class, 'store']);

Route::apiResource('unit-classes', Facility\UnitClassController::class);

Route::apiResource('unit-class-rates', Facility\UnitClassRateController::class)->only(['index', 'store']);

Route::apiResource('email-templates', Controllers\EmailTemplateController::class);

Route::apiResource('automations', Controllers\AutomationController::class);
Route::post('automations/{automation}/archive', [Controllers\AutomationController::class, 'archive']);
Route::post('automations/{automation}/unarchive', [Controllers\AutomationController::class, 'unarchive']);
Route::post('automations/{automation}/activate', [Controllers\AutomationController::class, 'activate']);
Route::post('automations/{automation}/deactivate', [Controllers\AutomationController::class, 'deactivate']);
Route::get('automations/{automation}/runs', [Controllers\AutomationController::class, 'runs']);
Route::get('automations/{automation}/runs/{run}', [Controllers\AutomationController::class, 'showRun']);

