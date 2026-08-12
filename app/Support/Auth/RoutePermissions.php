<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * Exhaustive route → permission (or Exempt) manifest for authenticated API routes.
 * Keys: "METHOD /api/uri". Public routes are omitted (task 00 allowlist).
 * Task 06 PermissionCoverageTest consumes this.
 */
final class RoutePermissions
{
    /**
     * @return array<string, Permission|Exempt>
     */
    public static function all(): array
    {
        return [

            // ===== Public (14) =====
            // PUBLIC  'GET /api/comms/unsubscribe/{token}'  // UnsubscribeController@__invoke — List-Unsubscribe HMAC
            // PUBLIC  'GET /api/legal-entities/{legal_entity}/stripe/public-key'  // LegalEntityStripeController@publicKey — Stripe publishable key (not secret)
            // PUBLIC  'GET /api/offers/token/{token}'  // OfferController@showByToken — offers.token crypto link
            // PUBLIC  'GET /api/pay/{token}'  // PublicPaymentController@show — payment-request token
            // PUBLIC  'GET /api/public/template-assets/{hash}/{filename}'  // TemplateAssetController@showPublic — content-hash public URL
            // PUBLIC  'POST /api/comms/unsubscribe/{token}'  // UnsubscribeController@__invoke — List-Unsubscribe HMAC
            // PUBLIC  'POST /api/login'  // EmployeeAuthController@login — issues Sanctum token
            // PUBLIC  'POST /api/offer-options/{offerOption}/select'  // OfferOptionController@select — anonymous accept after token load
            // PUBLIC  'POST /api/pay/{token}/intent'  // PublicPaymentController@intent — payment-request token
            // PUBLIC  'POST /api/webhooks/access/{webhookToken}'  // Webhooks\AccessWebhookController@__invoke — access webhook token
            // PUBLIC  'POST /api/webhooks/esign/{webhookToken}'  // Webhooks\EsignWebhookController@__invoke — e-sign webhook token
            // PUBLIC  'POST /api/webhooks/stripe/{accountToken}'  // Webhooks\StripeWebhookController@__invoke — Stripe signature
            // PUBLIC  'POST /api/webhooks/{provider}/{webhookUrlToken}'  // Webhooks\DeliveryWebhookController@__invoke — delivery webhook token
            // PUBLIC  'POST /api/webhooks/{provider}/{webhookUrlToken}/inbound'  // Webhooks\DeliveryWebhookController@__invoke — delivery inbound HMAC/token

            // ===== Facility (57) =====
            'DELETE /api/site-maps/{siteMap}' => Permission::SiteManage, // Facility\SiteMapController@destroy
            'DELETE /api/sites/{site}' => Permission::SiteManage, // Facility\SiteController@destroy
            'DELETE /api/unit-classes/{unit_class}' => Permission::CatalogueManage, // Facility\UnitClassController@destroy
            'DELETE /api/units/{unit}' => Permission::UnitManage, // Facility\UnitController@destroy
            'DELETE /api/units/{unit}/holds/{hold}' => Permission::UnitHoldManage, // Facility\UnitHoldController@destroy
            'GET /api/countries/options' => Exempt::reference('static ISO country list'), // Facility\CountryController@options
            'GET /api/discounts' => Permission::CatalogueManage, // DiscountController@index
            'GET /api/discounts/options' => Permission::OfferManage, // DiscountController@options — leasing needs resolve; CRUD stays CatalogueManage
            'GET /api/discounts/{discount}' => Permission::CatalogueManage, // DiscountController@show
            'GET /api/discounts/{discount}/resolve' => Permission::OfferManage, // DiscountController@resolve — leasing needs resolve; CRUD stays CatalogueManage
            'GET /api/insurance-rate-matrix' => Permission::CatalogueManage, // Facility\InsurancePriceMatrixController@index
            'GET /api/insurances' => Permission::CatalogueManage, // Facility\InsurancePlanController@index
            'GET /api/insurances/options' => Permission::CatalogueManage, // InsuranceController@options
            'GET /api/site-maps/{siteMap}' => Permission::SiteManage, // Facility\SiteMapController@show
            'GET /api/sites' => Permission::UnitView, // Facility\SiteController@index — no SiteView; estate picker/read via UnitView
            'GET /api/sites/options' => Permission::UnitView, // Facility\SiteController@options — no SiteView; estate picker/read via UnitView
            'GET /api/sites/{site}' => Permission::UnitView, // Facility\SiteController@show — no SiteView; estate picker/read via UnitView
            'GET /api/sites/{site}/maps' => Permission::SiteManage, // Facility\SiteMapController@index
            'GET /api/unit-class-occupancy-matrix' => Permission::CatalogueManage, // Facility\UnitClassOccupancyMatrixController@index
            'GET /api/unit-class-price-matrix' => Permission::CatalogueManage, // Facility\UnitClassPriceMatrixController@index
            'GET /api/unit-class-rates' => Permission::CatalogueManage, // Facility\UnitClassRateController@index
            'GET /api/unit-classes' => Permission::CatalogueManage, // Facility\UnitClassController@index
            'GET /api/unit-classes/options' => Permission::UnitView, // Facility\UnitClassController@options — estate picker per 03-policy-rollout
            'GET /api/unit-classes/{unitClass}/prices' => Permission::CatalogueManage, // Facility\UnitClassPriceController@index
            'GET /api/unit-classes/{unit_class}' => Permission::CatalogueManage, // Facility\UnitClassController@show
            'GET /api/units' => Permission::UnitView, // Facility\UnitController@index
            'GET /api/units/filters/schema' => Permission::UnitView, // Facility\UnitController@filterSchema
            'GET /api/units/options' => Permission::UnitView, // Facility\UnitController@options
            'GET /api/units/{unit}' => Permission::UnitView, // Facility\UnitController@show
            'GET /api/units/{unit}/holds' => Permission::UnitView, // Facility\UnitHoldController@index
            'GET /api/units/{unit}/occupancies' => Permission::UnitView, // Facility\UnitOccupancyController@index
            'PATCH /api/discounts/{discount}' => Permission::CatalogueManage, // DiscountController@update
            'PATCH /api/insurances/{insurance}' => Permission::CatalogueManage, // Facility\InsurancePlanController@update
            'PATCH /api/site-maps/{siteMap}' => Permission::SiteManage, // Facility\SiteMapController@update
            'PATCH /api/sites/{site}' => Permission::SiteManage, // Facility\SiteController@update
            'PATCH /api/unit-classes/{unit_class}' => Permission::CatalogueManage, // Facility\UnitClassController@update
            'PATCH /api/units/{unit}' => Permission::UnitManage, // Facility\UnitController@update
            'POST /api/discounts' => Permission::CatalogueManage, // DiscountController@store
            'POST /api/discounts/{discount}/archive' => Permission::CatalogueManage, // DiscountController@archive
            'POST /api/discounts/{discount}/unarchive' => Permission::CatalogueManage, // DiscountController@unarchive
            'POST /api/insurances' => Permission::CatalogueManage, // Facility\InsurancePlanController@store
            'POST /api/insurances/{insurance}/rates' => Permission::CatalogueManage, // Facility\InsuranceRateController@store
            'POST /api/sites' => Permission::SiteManage, // Facility\SiteController@store
            'POST /api/sites/{site}/archive' => Permission::SiteManage, // Facility\SiteController@archive
            'POST /api/sites/{site}/maps' => Permission::SiteManage, // Facility\SiteMapController@store
            'POST /api/sites/{site}/maps/validate' => Permission::SiteManage, // Facility\SiteMapController@validateSvg
            'POST /api/sites/{site}/unarchive' => Permission::SiteManage, // Facility\SiteController@unarchive
            'POST /api/unit-class-rates' => Permission::CatalogueManage, // Facility\UnitClassRateController@store
            'POST /api/unit-classes' => Permission::CatalogueManage, // Facility\UnitClassController@store
            'POST /api/unit-classes/{unitClass}/prices' => Permission::CatalogueManage, // Facility\UnitClassPriceController@store
            'POST /api/units' => Permission::UnitManage, // Facility\UnitController@store
            'POST /api/units/search' => Permission::UnitView, // Facility\UnitController@search
            'POST /api/units/{unit}/holds' => Permission::UnitHoldManage, // Facility\UnitHoldController@store
            'PUT /api/insurances/{insurance}' => Permission::CatalogueManage, // Facility\InsurancePlanController@update
            'PUT /api/sites/{site}' => Permission::SiteManage, // Facility\SiteController@update
            'PUT /api/unit-classes/{unit_class}' => Permission::CatalogueManage, // Facility\UnitClassController@update
            'PUT /api/units/{unit}' => Permission::UnitManage, // Facility\UnitController@update

            // ===== Leasing (66) =====
            'DELETE /api/contacts/{contact}' => Permission::ContactManage, // ContactController@destroy
            'DELETE /api/contacts/{contact}/addresses/{address}' => Permission::ContactManage, // ContactAddressController@destroy
            'DELETE /api/contacts/{contact}/channels/{channel}' => Permission::ContactManage, // ContactChannelController@destroy
            'DELETE /api/deals/{deal}' => Permission::DealManage, // DealController@destroy
            'DELETE /api/offer-options/{offerOption}' => Permission::OfferManage, // OfferOptionController@destroy
            'DELETE /api/offers/{offer}' => Permission::OfferManage, // OfferController@destroy
            'DELETE /api/reservations/{reservation}' => Permission::ReservationManage, // ReservationController@destroy
            'GET /api/contacts' => Permission::ContactView, // ContactController@index
            'GET /api/contacts/board' => Permission::ContactView, // ContactBoardController@index
            'GET /api/contacts/board/columns/{status}' => Permission::ContactView, // ContactBoardController@column
            'GET /api/contacts/filters/schema' => Permission::ContactView, // ContactController@filterSchema
            'GET /api/contacts/options' => Permission::ContactView, // ContactController@options
            'GET /api/contacts/{contact}' => Permission::ContactView, // ContactController@show
            'GET /api/contacts/{contact}/interactions' => Permission::ContactView, // ContactInteractionController@index
            'GET /api/deals' => Permission::DealManage, // DealController@index
            'GET /api/deals/board' => Permission::DealManage, // DealBoardController@index
            'GET /api/deals/board/columns/{status}' => Permission::DealManage, // DealBoardController@column
            'GET /api/deals/filters/schema' => Permission::DealManage, // DealController@filterSchema
            'GET /api/deals/options' => Permission::DealManage, // DealController@options
            'GET /api/deals/{deal}' => Permission::DealManage, // DealController@show
            'GET /api/offers' => Permission::OfferManage, // OfferController@index
            'GET /api/offers/board' => Permission::OfferManage, // OfferBoardController@index
            'GET /api/offers/board/columns/{status}' => Permission::OfferManage, // OfferBoardController@column
            'GET /api/offers/filters/schema' => Permission::OfferManage, // OfferController@filterSchema
            'GET /api/offers/{offer}' => Permission::OfferManage, // OfferController@show
            'GET /api/reservations' => Permission::ReservationManage, // ReservationController@index
            'GET /api/reservations/board' => Permission::ReservationManage, // ReservationBoardController@index
            'GET /api/reservations/board/columns/{status}' => Permission::ReservationManage, // ReservationBoardController@column
            'GET /api/reservations/filters/schema' => Permission::ReservationManage, // ReservationController@filterSchema
            'GET /api/reservations/{reservation}' => Permission::ReservationManage, // ReservationController@show
            'GET /api/tasks' => Permission::ContactView, // TaskController@index
            'GET /api/tasks/board' => Permission::ContactView, // TaskBoardController@index
            'GET /api/tasks/board/columns/{status}' => Permission::ContactView, // TaskBoardController@column
            'PATCH /api/contacts/{contact}' => Permission::ContactManage, // ContactController@update
            'PATCH /api/contacts/{contact}/addresses/{address}' => Permission::ContactManage, // ContactAddressController@update
            'PATCH /api/contacts/{contact}/channels/{channel}' => Permission::ContactManage, // ContactChannelController@update
            'PATCH /api/contacts/{contact}/status' => Permission::ContactManage, // ContactController@updateStatus
            'PATCH /api/contacts/{contact}/tasks/{task}' => Permission::ContactManage, // ContactTaskController@update
            'PATCH /api/deals/{deal}' => Permission::DealManage, // DealController@update
            'PATCH /api/deals/{deal}/status' => Permission::DealManage, // DealController@updateStatus
            'PATCH /api/deals/{deal}/tasks/{task}' => Permission::DealManage, // DealTaskController@update
            'PATCH /api/offer-options/{offerOption}' => Permission::OfferManage, // OfferOptionController@update
            'PATCH /api/offers/{offer}' => Permission::OfferManage, // OfferController@update
            'PATCH /api/offers/{offer}/status' => Permission::OfferSend, // OfferController@updateStatus — controller: OfferSend only when →sent; else OfferManage
            'PATCH /api/reservations/{reservation}' => Permission::ReservationManage, // ReservationController@update
            'PATCH /api/reservations/{reservation}/status' => Permission::ReservationManage, // ReservationController@updateStatus
            'PATCH /api/tasks/{task}/status' => Permission::ContactManage, // TaskController@updateStatus
            'POST /api/contacts' => Permission::ContactManage, // ContactController@store
            'POST /api/contacts/search' => Permission::ContactView, // ContactController@search
            'POST /api/contacts/{contact}/addresses' => Permission::ContactManage, // ContactAddressController@store
            'POST /api/contacts/{contact}/channels' => Permission::ContactManage, // ContactChannelController@store
            'POST /api/contacts/{contact}/interactions' => Permission::ContactManage, // ContactInteractionController@store
            'POST /api/contacts/{contact}/tasks' => Permission::ContactManage, // ContactTaskController@store
            'POST /api/deals' => Permission::DealManage, // DealController@store
            'POST /api/deals/search' => Permission::DealManage, // DealController@search
            'POST /api/deals/{deal}/tasks' => Permission::DealManage, // DealTaskController@store
            'POST /api/notes' => Permission::ContactManage, // NoteController@store — actual permission resolved per note type via AttributeEntityType::managePermission(), not a single static case
            'POST /api/offer-options' => Permission::OfferManage, // OfferOptionController@store
            'POST /api/offers' => Permission::OfferManage, // OfferController@store
            'POST /api/offers/search' => Permission::OfferManage, // OfferController@search
            'POST /api/reservations' => Permission::ReservationManage, // ReservationController@store
            'POST /api/reservations/search' => Permission::ReservationManage, // ReservationController@search
            'PUT /api/contacts/{contact}' => Permission::ContactManage, // ContactController@update
            'PUT /api/deals/{deal}' => Permission::DealManage, // DealController@update
            'PUT /api/offers/{offer}' => Permission::OfferManage, // OfferController@update
            'PUT /api/reservations/{reservation}' => Permission::ReservationManage, // ReservationController@update

            // ===== Contracts (35) =====
            'DELETE /api/contracts/{contract}' => Permission::ContractSign, // ContractController@destroy
            'DELETE /api/contracts/{contract}/discount' => Permission::ContractSign, // ContractController@destroyDiscount
            'GET /api/contracts' => Permission::ContractView, // ContractController@index
            'GET /api/contracts/board' => Permission::ContractView, // ContractBoardController@index
            'GET /api/contracts/board/columns/{status}' => Permission::ContractView, // ContractBoardController@column
            'GET /api/contracts/filters/schema' => Permission::ContractView, // ContractController@filterSchema
            'GET /api/contracts/{contract}' => Permission::ContractView, // ContractController@show
            'GET /api/contracts/{contract}/documents' => Permission::ContractView, // ContractDocumentController@index
            'GET /api/contracts/{contract}/documents/preview' => Permission::ContractView, // ContractDocumentController@preview
            'GET /api/contracts/{contract}/documents/{document}/pdf' => Permission::ContractView, // ContractDocumentController@pdf
            'GET /api/contracts/{contract}/envelopes' => Permission::ContractView, // EsignEnvelopeController@index
            'GET /api/contracts/{contract}/envelopes/{envelope}/certificate' => Permission::ContractView, // EsignEnvelopeController@certificate
            'GET /api/contracts/{contract}/envelopes/{envelope}/signed-pdf' => Permission::ContractView, // EsignEnvelopeController@signedPdf
            'GET /api/contracts/{contract}/next-bill' => Permission::ContractView, // ContractController@nextBill
            'GET /api/reservations/{reservation}/convert-preview' => Permission::ContractSign, // ReservationController@convertPreview
            'PATCH /api/contracts/{contract}' => Permission::ContractSign, // ContractController@update
            'POST /api/contracts' => Permission::ContractSign, // ContractController@store
            'POST /api/contracts/search' => Permission::ContractView, // ContractController@search
            'POST /api/contracts/{contract}/cancel' => Permission::ContractSign, // ContractController@cancel
            'POST /api/contracts/{contract}/documents' => Permission::ContractSign, // ContractDocumentController@store
            'POST /api/contracts/{contract}/documents/{document}/regenerate' => Permission::ContractSign, // ContractDocumentController@regenerate
            'POST /api/contracts/{contract}/envelopes' => Permission::EsignSend, // EsignEnvelopeController@store
            'POST /api/contracts/{contract}/envelopes/{envelope}/cancel' => Permission::EsignSend, // EsignEnvelopeController@cancel
            'POST /api/contracts/{contract}/envelopes/{envelope}/resend' => Permission::EsignSend, // EsignEnvelopeController@resend
            'POST /api/contracts/{contract}/notice' => Permission::ContractVacate, // ContractController@notice
            'POST /api/contracts/{contract}/notice-withdraw' => Permission::ContractVacate, // ContractController@noticeWithdraw
            'POST /api/contracts/{contract}/rate-changes' => Permission::ContractRateChange, // ContractRateChangeController@store
            'POST /api/contracts/{contract}/restore-access' => Permission::AccessManage, // ContractController@restoreAccess
            'POST /api/contracts/{contract}/suspend-access' => Permission::AccessManage, // ContractController@suspendAccess
            'POST /api/contracts/{contract}/transfer' => Permission::ContractTransfer, // ContractController@transfer
            'POST /api/contracts/{contract}/transfer-preview' => Permission::ContractTransfer, // ContractController@transferPreview
            'POST /api/contracts/{contract}/vacate' => Permission::ContractVacate, // ContractController@vacate
            'POST /api/contracts/{contract}/vacate-preview' => Permission::ContractVacate, // ContractController@vacatePreview
            'POST /api/reservations/{reservation}/convert' => Permission::ContractSign, // ReservationController@convert
            'PUT /api/contracts/{contract}' => Permission::ContractSign, // ContractController@update

            // ===== Billing (47) =====
            'DELETE /api/legal-entities/{legal_entity}' => Permission::LegalEntityManage, // LegalEntityController@destroy
            'DELETE /api/legal-entities/{legal_entity}/stripe-settings' => Permission::CredentialManage, // LegalEntityStripeController@destroy
            'DELETE /api/payment-methods/{paymentMethod}' => Permission::PaymentRecord, // ContactPaymentMethodController@destroy
            'GET /api/billing-runs' => Permission::BillingRunExecute, // BillingRunController@index
            'GET /api/billing-runs/{billingRun}' => Permission::BillingRunExecute, // BillingRunController@show
            'GET /api/billing/overdue' => Permission::InvoiceView, // BillingOverdueController@index
            'GET /api/contacts/{contact}/payment-methods' => Permission::PaymentView, // ContactPaymentMethodController@index
            'GET /api/contacts/{contact}/transactions' => Permission::PaymentView, // ContactController@transactions
            'GET /api/contracts/{contract}/autopay' => Permission::PaymentView, // ContractAutopayController@show
            'GET /api/contracts/{contract}/payment-requests' => Permission::PaymentView, // PaymentRequestController@index
            'GET /api/invoices' => Permission::InvoiceView, // InvoiceController@index
            'GET /api/invoices/{invoice}' => Permission::InvoiceView, // InvoiceController@show
            'GET /api/invoices/{invoice}/pdf' => Permission::InvoiceView, // InvoiceController@pdf
            'GET /api/legal-entities' => Permission::LegalEntityManage, // LegalEntityController@index
            'GET /api/legal-entities/options' => Permission::InvoiceView, // LegalEntityController@options — picker; accountants need this
            'GET /api/legal-entities/{legal_entity}' => Permission::LegalEntityManage, // LegalEntityController@show
            'GET /api/legal-entities/{legal_entity}/invoice-series' => Permission::LegalEntityManage, // InvoiceSeriesController@index
            'GET /api/legal-entities/{legal_entity}/stripe-settings' => Permission::CredentialManage, // LegalEntityStripeController@show
            'GET /api/settings/billing' => Permission::BillingSettingsManage, // Facility\SettingController@showBilling
            'GET /api/tax-rates' => Permission::TaxRateManage, // TaxRateController@index
            'GET /api/tax-rates/options' => Permission::TaxRateManage, // TaxRateController@options
            'PATCH /api/invoice-series/{invoice_series}' => Permission::LegalEntityManage, // InvoiceSeriesController@update
            'PATCH /api/legal-entities/{legal_entity}' => Permission::LegalEntityManage, // LegalEntityController@update
            'PATCH /api/payment-methods/{paymentMethod}' => Permission::PaymentRecord, // ContactPaymentMethodController@update
            'PATCH /api/settings/billing' => Permission::BillingSettingsManage, // Facility\SettingController@updateBilling
            'PATCH /api/tax-rates/{taxRate}' => Permission::TaxRateManage, // TaxRateController@update
            'POST /api/billing-runs' => Permission::BillingRunExecute, // BillingRunController@store
            'POST /api/contacts/{contact}/payment-methods/setup' => Permission::PaymentRecord, // ContactPaymentMethodController@setup
            'POST /api/contracts/{contract}/autopay/retry' => Permission::PaymentRecord, // ContractAutopayController@retry
            'POST /api/contracts/{contract}/invoices' => Permission::InvoiceIssue, // InvoiceController@storeForContract
            'POST /api/contracts/{contract}/payment-requests' => Permission::PaymentRecord, // PaymentRequestController@store
            'POST /api/contracts/{contract}/payments' => Permission::PaymentRecord, // PaymentController@store
            'POST /api/invoice-series/{invoice_series}/archive' => Permission::LegalEntityManage, // InvoiceSeriesController@archive
            'POST /api/invoice-series/{invoice_series}/unarchive' => Permission::LegalEntityManage, // InvoiceSeriesController@unarchive
            'POST /api/invoices/{invoice}/rectify' => Permission::InvoiceRectify, // InvoiceController@rectify
            'POST /api/legal-entities' => Permission::LegalEntityManage, // LegalEntityController@store
            'POST /api/legal-entities/{legal_entity}/archive' => Permission::LegalEntityManage, // LegalEntityController@archive
            'POST /api/legal-entities/{legal_entity}/invoice-series' => Permission::LegalEntityManage, // InvoiceSeriesController@store
            'POST /api/legal-entities/{legal_entity}/stripe-settings/webhook' => Permission::CredentialManage, // LegalEntityStripeController@createWebhook
            'POST /api/legal-entities/{legal_entity}/unarchive' => Permission::LegalEntityManage, // LegalEntityController@unarchive
            'POST /api/payment-requests/{paymentRequest}/cancel' => Permission::PaymentRecord, // PaymentRequestController@cancel
            'POST /api/payments/{payment}/reverse' => Permission::PaymentRefund, // PaymentController@reverse
            'POST /api/tax-rates' => Permission::TaxRateManage, // TaxRateController@store
            'POST /api/tax-rates/{taxRate}/default' => Permission::TaxRateManage, // TaxRateController@setDefault
            'PUT /api/contracts/{contract}/autopay' => Permission::PaymentRecord, // ContractAutopayController@update
            'PUT /api/legal-entities/{legal_entity}' => Permission::LegalEntityManage, // LegalEntityController@update
            'PUT /api/legal-entities/{legal_entity}/stripe-settings' => Permission::CredentialManage, // LegalEntityStripeController@update

            // ===== Delinquency (19) =====
            'GET /api/delinquencies' => Permission::DelinquencyView, // DelinquencyController@index
            'GET /api/delinquencies/{delinquency}' => Permission::DelinquencyView, // DelinquencyController@show
            'GET /api/delinquency-policies' => Permission::DelinquencyView, // DelinquencyPolicyController@index
            'GET /api/delinquency-policies/options' => Permission::DelinquencyView, // DelinquencyPolicyController@options
            'GET /api/delinquency-policies/{delinquencyPolicy}' => Permission::DelinquencyView, // DelinquencyPolicyController@show
            'PATCH /api/delinquency-policies/{delinquencyPolicy}' => Permission::SettingsManage, // DelinquencyPolicyController@update
            'POST /api/contract-notices/{contractNotice}/mark-sent' => Permission::DelinquencyAct, // ContractNoticeController@markSent
            'POST /api/delinquencies/{delinquency}/assess-fee' => Permission::DelinquencyAct, // DelinquencyController@assessFee
            'POST /api/delinquencies/{delinquency}/notices' => Permission::DelinquencyAct, // DelinquencyController@recordNotice
            'POST /api/delinquencies/{delinquency}/overlock' => Permission::DelinquencyAct, // DelinquencyController@overlock
            'POST /api/delinquencies/{delinquency}/pause' => Permission::DelinquencyAct, // DelinquencyController@pause
            'POST /api/delinquencies/{delinquency}/release-overlock' => Permission::DelinquencyAct, // DelinquencyController@releaseOverlock
            'POST /api/delinquencies/{delinquency}/restore-access' => Permission::DelinquencyAct, // DelinquencyController@restoreAccess
            'POST /api/delinquencies/{delinquency}/resume' => Permission::DelinquencyAct, // DelinquencyController@resume
            'POST /api/delinquencies/{delinquency}/suspend-access' => Permission::DelinquencyAct, // DelinquencyController@suspendAccess
            'POST /api/delinquencies/{delinquency}/write-off' => Permission::DelinquencyWriteOff, // DelinquencyController@writeOff
            'POST /api/delinquency-policies' => Permission::SettingsManage, // DelinquencyPolicyController@store
            'POST /api/delinquency-policies/{delinquencyPolicy}/archive' => Permission::SettingsManage, // DelinquencyPolicyController@archive
            'POST /api/delinquency-policies/{delinquencyPolicy}/unarchive' => Permission::SettingsManage, // DelinquencyPolicyController@unarchive

            // ===== Comms (72) =====
            'DELETE /api/settings/ai-provider-accounts/{aiProviderAccount}' => Permission::CredentialManage, // AiProviderAccountController@destroy
            'DELETE /api/settings/analytics-accounts/{analyticsAccount}' => Permission::CredentialManage, // AnalyticsAccountController@destroy
            'DELETE /api/settings/insight-reports/{insightReport}' => Permission::SettingsManage, // InsightReportController@destroy — aliases archive
            'DELETE /api/settings/communications/call/aircall/users/{aircallUserId}' => Permission::CredentialManage, // AircallUserLinkController@unlink
            'DELETE /api/settings/communications/{channel}/webhook' => Permission::CredentialManage, // Facility\CommunicationAccountController@deleteWebhook
            'DELETE /api/settings/communications/{channel}/{provider}' => Permission::CredentialManage, // Facility\CommunicationAccountController@destroy
            'DELETE /api/settings/esign' => Permission::CredentialManage, // EsignProviderAccountController@destroy
            'DELETE /api/template-assets/{templateAsset}' => Permission::TemplateManage, // TemplateAssetController@destroy
            'DELETE /api/template-families/{templateFamily}/variants/{variant}' => Permission::TemplateManage, // TemplateFamilyController@destroyVariant
            'DELETE /api/template-families/{template_family}' => Permission::TemplateManage, // TemplateFamilyController@destroy
            'GET /api/calls/availability' => Permission::CallPlace, // CallController@availability
            'GET /api/comms-triage' => Permission::InboxView, // CommsTriageController@index
            'GET /api/comms-triage/{commsTriage}' => Permission::InboxView, // CommsTriageController@show
            'GET /api/inbox/badge' => Permission::InboxView, // InboxController@badge
            'GET /api/inbox/threads' => Permission::InboxView, // InboxController@index
            'GET /api/inbox/threads/{messageThread}' => Permission::InboxView, // InboxController@show
            'GET /api/inbox/threads/{messageThread}/compose-context' => Permission::InboxView, // InboxController@composeContext
            'GET /api/inbox/threads/{messageThread}/context' => Permission::InboxView, // InboxController@context
            'GET /api/inbox/threads/{messageThread}/move-targets' => Permission::InboxView, // InboxController@moveTargets
            'GET /api/message-attachments/{messageAttachment}/download' => Permission::InboxView, // MessageAttachmentController@download
            'GET /api/messages/{message}/recording' => Permission::InboxView, // MessageController@recording
            'GET /api/messages/{message}/wrapup' => Permission::InboxView, // MessageController@showWrapup
            'GET /api/settings/ai-provider-accounts' => Permission::CredentialManage, // AiProviderAccountController@index
            'GET /api/settings/ai-providers' => Permission::CredentialManage, // AiProviderAccountController@providers
            'GET /api/settings/analytics-accounts' => Permission::CredentialManage, // AnalyticsAccountController@index
            'GET /api/settings/analytics-accounts/{analyticsAccount}/resources' => Permission::CredentialManage, // AnalyticsAccountController@resources
            'GET /api/settings/analytics-accounts/{analyticsAccount}/resources/{kind}/{ref}/params' => Permission::CredentialManage, // AnalyticsAccountController@resourceParams
            'GET /api/settings/analytics-providers' => Permission::CredentialManage, // AnalyticsAccountController@providers
            'GET /api/settings/communications' => Permission::CredentialManage, // Facility\CommunicationAccountController@index
            'GET /api/settings/communications/call/aircall/users' => Permission::CredentialManage, // AircallUserLinkController@index
            'GET /api/settings/esign' => Permission::CredentialManage, // EsignProviderAccountController@show
            'GET /api/sites/{site}/sender-identities' => Permission::CredentialManage, // Facility\SiteSenderIdentityController@index
            'GET /api/template-builder/sample-contexts' => Permission::TemplateManage, // TemplateFamilyController@sampleContexts
            'GET /api/template-families' => Permission::TemplateManage, // TemplateFamilyController@index
            'GET /api/template-families/{template_family}' => Permission::TemplateManage, // TemplateFamilyController@show
            'GET /api/whatsapp-templates' => Permission::TemplateManage, // WhatsappTemplateController@index
            'GET /api/whatsapp-templates/{whatsappTemplate}' => Permission::TemplateManage, // WhatsappTemplateController@show
            'PATCH /api/settings/ai-provider-accounts/{aiProviderAccount}' => Permission::CredentialManage, // AiProviderAccountController@update
            'PATCH /api/settings/analytics-accounts/{analyticsAccount}' => Permission::CredentialManage, // AnalyticsAccountController@update
            'PATCH /api/template-families/{template_family}' => Permission::TemplateManage, // TemplateFamilyController@update
            'PATCH /api/whatsapp-templates/{whatsappTemplate}' => Permission::TemplateManage, // WhatsappTemplateController@update
            'POST /api/calls/dial' => Permission::CallPlace, // CallController@dial
            'POST /api/comms-triage/{commsTriage}/attach' => Permission::InboxAssign, // CommsTriageController@attach
            'POST /api/comms-triage/{commsTriage}/create-and-attach' => Permission::InboxAssign, // CommsTriageController@createAndAttach
            'POST /api/comms-triage/{commsTriage}/discard' => Permission::InboxAssign, // CommsTriageController@discard
            'POST /api/inbox/attachments' => Permission::InboxSend, // MessageAttachmentController@store
            'POST /api/inbox/compose' => Permission::InboxSend, // InboxController@compose
            'POST /api/inbox/threads/{messageThread}/assign' => Permission::InboxAssign, // InboxController@assign
            'POST /api/inbox/threads/{messageThread}/read' => Permission::InboxView, // InboxController@read
            'POST /api/inbox/threads/{messageThread}/reply' => Permission::InboxSend, // InboxController@reply
            'POST /api/inbox/threads/{messageThread}/unread' => Permission::InboxView, // InboxController@unread
            'POST /api/messages/{message}/move-thread' => Permission::InboxAssign, // MessageController@moveThread
            'POST /api/settings/ai-provider-accounts' => Permission::CredentialManage, // AiProviderAccountController@store
            'POST /api/settings/ai-provider-accounts/{aiProviderAccount}/archive' => Permission::CredentialManage, // AiProviderAccountController@archive
            'POST /api/settings/ai-provider-accounts/{aiProviderAccount}/default' => Permission::CredentialManage, // AiProviderAccountController@setDefault
            'POST /api/settings/ai-provider-accounts/{aiProviderAccount}/unarchive' => Permission::CredentialManage, // AiProviderAccountController@unarchive
            'POST /api/settings/ai-provider-accounts/{aiProviderAccount}/verify' => Permission::CredentialManage, // AiProviderAccountController@verify
            'POST /api/settings/analytics-accounts' => Permission::CredentialManage, // AnalyticsAccountController@store
            'POST /api/settings/analytics-accounts/{analyticsAccount}/archive' => Permission::CredentialManage, // AnalyticsAccountController@archive
            'POST /api/settings/analytics-accounts/{analyticsAccount}/default' => Permission::CredentialManage, // AnalyticsAccountController@setDefault
            'POST /api/settings/analytics-accounts/{analyticsAccount}/unarchive' => Permission::CredentialManage, // AnalyticsAccountController@unarchive
            'POST /api/settings/analytics-accounts/{analyticsAccount}/verify' => Permission::CredentialManage, // AnalyticsAccountController@verify
            'POST /api/settings/communications/call/aircall/users/sync' => Permission::CredentialManage, // AircallUserLinkController@sync
            'POST /api/settings/communications/{channel}/webhook' => Permission::CredentialManage, // Facility\CommunicationAccountController@createWebhook
            'POST /api/settings/esign/webhook' => Permission::CredentialManage, // EsignProviderAccountController@createWebhook
            'POST /api/template-assets' => Permission::TemplateManage, // TemplateAssetController@store
            'POST /api/template-families' => Permission::TemplateManage, // TemplateFamilyController@store
            'POST /api/template-families/{templateFamily}/archive' => Permission::TemplateManage, // TemplateFamilyController@archive
            'POST /api/template-families/{templateFamily}/variants' => Permission::TemplateManage, // TemplateFamilyController@storeVariant
            'POST /api/template-families/{templateFamily}/variants/{variant}/preview' => Permission::TemplateManage, // TemplateFamilyController@preview
            'POST /api/template-families/{templateFamily}/variants/{variant}/test-send' => Permission::TemplateManage, // TemplateFamilyController@testSend
            'POST /api/whatsapp-templates' => Permission::TemplateManage, // WhatsappTemplateController@store
            'POST /api/whatsapp-templates/sync' => Permission::TemplateManage, // WhatsappTemplateController@sync
            'POST /api/whatsapp-templates/{whatsappTemplate}/archive' => Permission::TemplateManage, // WhatsappTemplateController@archive
            'POST /api/whatsapp-templates/{whatsappTemplate}/clone' => Permission::TemplateManage, // WhatsappTemplateController@clone
            'POST /api/whatsapp-templates/{whatsappTemplate}/submit' => Permission::TemplateManage, // WhatsappTemplateController@submit
            'PUT /api/messages/{message}/wrapup' => Permission::InboxSend, // MessageController@upsertWrapup
            'PUT /api/settings/communications/call/aircall/users/{aircallUserId}' => Permission::CredentialManage, // AircallUserLinkController@map
            'PUT /api/settings/communications/{channel}' => Permission::CredentialManage, // Facility\CommunicationAccountController@update
            'PUT /api/settings/esign' => Permission::CredentialManage, // EsignProviderAccountController@update
            'PUT /api/sites/{site}/sender-identities/{channel}' => Permission::CredentialManage, // Facility\SiteSenderIdentityController@update
            'PUT /api/template-families/{templateFamily}/variants/{variant}' => Permission::TemplateManage, // TemplateFamilyController@updateVariant
            'PUT /api/template-families/{template_family}' => Permission::TemplateManage, // TemplateFamilyController@update
            'PUT /api/whatsapp-templates/{whatsappTemplate}' => Permission::TemplateManage, // WhatsappTemplateController@update

            // ===== Operations (72) =====
            'DELETE /api/automations/{automation}' => Permission::AutomationManage, // AutomationController@destroy
            'DELETE /api/copilot/conversations/{conversation}' => Permission::ContactView, // CopilotController@destroy — placeholder until enum case exists
            'DELETE /api/playbooks/{playbook}' => Permission::PlaybookManage, // PlaybookController@destroy
            'DELETE /api/settings/access' => Permission::CredentialManage, // AccessProviderAccountController@destroy — provider account
            'DELETE /api/settings/object-customization/fields/{field}' => Permission::SettingsManage, // ObjectCustomizationController@destroyField
            'DELETE /api/settings/object-customization/groups/{group}' => Permission::SettingsManage, // ObjectCustomizationController@destroyGroup
            'GET /api/access/events' => Permission::AccessView, // AccessEventController@index
            'GET /api/activities' => Permission::ActivityView, // ActivityController@index
            'GET /api/attribute-definitions' => Permission::SettingsManage, // AttributeDefinitionController@index
            'GET /api/attribute-definitions/{attributeDefinition}' => Permission::SettingsManage, // AttributeDefinitionController@show
            'GET /api/automations' => Permission::AutomationView, // AutomationController@index
            'GET /api/automations/trigger-fields/{objectType}' => Permission::AutomationView, // AutomationController@triggerFields
            'GET /api/automations/{automation}' => Permission::AutomationView, // AutomationController@show
            'GET /api/automations/{automation}/runs' => Permission::AutomationView, // AutomationController@runs
            'GET /api/automations/{automation}/runs/{run}' => Permission::AutomationView, // AutomationController@showRun
            'GET /api/contacts/{contact}/access-events' => Permission::AccessView, // AccessEventController@forContact
            'GET /api/copilot/conversations' => Permission::ContactView, // CopilotController@index — placeholder until enum case exists
            'GET /api/copilot/conversations/{conversation}' => Permission::ContactView, // CopilotController@show — placeholder until enum case exists
            'GET /api/employees/options' => Permission::InboxAssign, // EmployeeController@options — assignee picker (inbox/tasks)
            'GET /api/insights' => Permission::ReportView, // InsightReportController@nav — registry-driven Insights nav feed
            'POST /api/insights/{key}/embed' => Permission::ReportView, // InsightReportController@embed — server-side embed mint
            'GET /api/insights/ai-usage' => Permission::ReportView, // AiUsageInsightsController@index — aggregate AI usage (company report)
            'GET /api/insights/ai-usage/me' => Exempt::self('own AI usage'), // AiUsageInsightsController@me
            'GET /api/playbooks' => Permission::PlaybookManage, // PlaybookController@index
            'GET /api/playbooks/{playbook}' => Permission::PlaybookManage, // PlaybookController@show
            'GET /api/playbooks/{playbook}/enrolments' => Permission::PlaybookManage, // PlaybookController@enrolments
            'GET /api/reports/{name}' => Permission::ReportView, // ReportController@show — branch to ReportFinancialView by report name in controller
            'GET /api/settings/access' => Permission::CredentialManage, // AccessProviderAccountController@show — provider account
            'GET /api/settings/access/points' => Permission::AccessManage, // AccessPointController@index — access point catalogue
            'GET /api/settings/activity-log' => Permission::SettingsManage, // Facility\SettingController@showActivityLog
            'GET /api/settings/general' => Permission::SettingsManage, // Facility\SettingController@showGeneral
            'GET /api/settings/insight-reports' => Permission::SettingsManage, // InsightReportController@index
            'GET /api/settings/insight-reports/{insightReport}' => Permission::SettingsManage, // InsightReportController@show
            'GET /api/settings/leasing' => Permission::SettingsManage, // Facility\SettingController@showLeasing
            'GET /api/settings/object-customization/{entityType}' => Permission::SettingsManage, // ObjectCustomizationController@show
            'GET /api/units/{unit}/access-events' => Permission::AccessView, // AccessEventController@forUnit
            'GET /api/{entityType}/attribute-definitions' => Permission::ContactManage, // AttributeDefinitionController@forEntity — actual permission resolved per entityType via AttributeEntityType::managePermission(), not a single static case
            'GET /api/{entityType}/{entityId}/attribute-values' => Permission::ContactView, // AttributeValueController@index — actual permission resolved per entityType via AttributeEntityType::viewPermission(), not a single static case
            'PATCH /api/attribute-definitions/{attributeDefinition}' => Permission::SettingsManage, // AttributeDefinitionController@update
            'PATCH /api/attribute-values' => Permission::ContactManage, // AttributeValueController@upsert — actual permission resolved per entityType via AttributeEntityType::managePermission(), not a single static case
            'PATCH /api/automations/{automation}' => Permission::AutomationManage, // AutomationController@update
            'PATCH /api/playbooks/{playbook}' => Permission::PlaybookManage, // PlaybookController@update
            'PATCH /api/settings/access/points/{accessPoint}' => Permission::AccessManage, // AccessPointController@update — access point catalogue
            'PATCH /api/settings/activity-log' => Permission::SettingsManage, // Facility\SettingController@updateActivityLog
            'PATCH /api/settings/general' => Permission::SettingsManage, // Facility\SettingController@updateGeneral
            'PATCH /api/settings/insight-reports/{insightReport}' => Permission::SettingsManage, // InsightReportController@update
            'PATCH /api/settings/leasing' => Permission::SettingsManage, // Facility\SettingController@updateLeasing
            'PATCH /api/settings/object-customization/fields/{field}' => Permission::SettingsManage, // ObjectCustomizationController@updateField
            'PATCH /api/settings/object-customization/groups/{group}' => Permission::SettingsManage, // ObjectCustomizationController@updateGroup
            'POST /api/access/grants/{accessGrant}/retry' => Permission::AccessManage, // AccessGrantController@retry
            'POST /api/attribute-definitions' => Permission::SettingsManage, // AttributeDefinitionController@store
            'POST /api/attribute-definitions/{attributeDefinition}/archive' => Permission::SettingsManage, // AttributeDefinitionController@archive
            'POST /api/attribute-definitions/{attributeDefinition}/unarchive' => Permission::SettingsManage, // AttributeDefinitionController@unarchive
            'POST /api/automation-runs/{run}/cancel' => Permission::AutomationManage, // AutomationController@cancelRun
            'POST /api/automations' => Permission::AutomationManage, // AutomationController@store
            'POST /api/automations/{automation}/activate' => Permission::AutomationManage, // AutomationController@activate
            'POST /api/automations/{automation}/archive' => Permission::AutomationManage, // AutomationController@archive
            'POST /api/automations/{automation}/deactivate' => Permission::AutomationManage, // AutomationController@deactivate
            'POST /api/automations/{automation}/unarchive' => Permission::AutomationManage, // AutomationController@unarchive
            'POST /api/copilot/conversations' => Permission::ContactView, // CopilotController@store — placeholder until enum case exists
            'POST /api/copilot/conversations/{conversation}/messages' => Permission::ContactView, // CopilotController@storeMessage — placeholder until enum case exists
            'POST /api/copilot/conversations/{conversation}/decisions' => Permission::ContactView, // CopilotController@storeDecisions — placeholder until enum case exists

            'POST /api/playbooks' => Permission::PlaybookManage, // PlaybookController@store
            'POST /api/playbooks/{playbook}/activate' => Permission::PlaybookManage, // PlaybookController@activate
            'POST /api/playbooks/{playbook}/deactivate' => Permission::PlaybookManage, // PlaybookController@deactivate
            'POST /api/playbooks/{playbook}/exit-enrolments' => Permission::PlaybookManage, // PlaybookController@exitEnrolments
            'POST /api/settings/access/points' => Permission::AccessManage, // AccessPointController@store — access point catalogue
            'POST /api/settings/access/points/bulk-assign' => Permission::AccessManage, // AccessPointController@bulkAssign — access point catalogue
            'POST /api/settings/access/points/refresh' => Permission::CredentialManage, // AccessProviderAccountController@refreshPoints — provider account
            'POST /api/settings/access/points/suggest' => Permission::AccessManage, // AccessPointController@suggest — access point catalogue
            'POST /api/settings/access/points/{accessPoint}/archive' => Permission::AccessManage, // AccessPointController@archive — access point catalogue
            'POST /api/settings/access/unknown-grants/revoke' => Permission::CredentialManage, // AccessProviderAccountController@revokeUnknownGrant — provider account
            'POST /api/settings/access/webhook' => Permission::CredentialManage, // AccessProviderAccountController@createWebhook — provider account
            'POST /api/settings/insight-reports' => Permission::SettingsManage, // InsightReportController@store
            'POST /api/settings/insight-reports/reorder' => Permission::SettingsManage, // InsightReportController@reorder
            'POST /api/settings/insight-reports/{insightReport}/archive' => Permission::SettingsManage, // InsightReportController@archive
            'POST /api/settings/insight-reports/{insightReport}/unarchive' => Permission::SettingsManage, // InsightReportController@unarchive
            'POST /api/settings/insight-reports/{insightReport}/validate' => Permission::SettingsManage, // InsightReportController@validateReport
            'POST /api/settings/object-customization/groups/{group}/fields' => Permission::SettingsManage, // ObjectCustomizationController@storeField
            'POST /api/settings/object-customization/groups/{group}/fields/reorder' => Permission::SettingsManage, // ObjectCustomizationController@reorderFields
            'POST /api/settings/object-customization/{entityType}/groups' => Permission::SettingsManage, // ObjectCustomizationController@storeGroup
            'POST /api/settings/object-customization/{entityType}/groups/reorder' => Permission::SettingsManage, // ObjectCustomizationController@reorderGroups
            'PUT /api/automations/{automation}' => Permission::AutomationManage, // AutomationController@update
            'PUT /api/playbooks/{playbook}' => Permission::PlaybookManage, // PlaybookController@update
            'PUT /api/settings/access' => Permission::CredentialManage, // AccessProviderAccountController@update — provider account

            // ===== RBAC =====
            'DELETE /api/employees/{employee}/invitations/{invitation}' => Permission::RbacManage, // EmployeeController@destroyInvitation
            'DELETE /api/employees/{employee}/roles/{grant}' => Permission::RbacManage, // EmployeeController@destroyRole
            'GET /api/employees' => Permission::RbacManage, // EmployeeController@index
            'GET /api/employees/{employee}/roles' => Permission::RbacManage, // EmployeeController@roles
            'GET /api/permissions' => Permission::RbacManage, // RbacController@permissions — role editor
            'GET /api/roles' => Permission::RbacManage, // RbacController@roles — role editor
            'GET /api/user' => Exempt::self('own identity'), // EmployeeAuthController@me
            'PATCH /api/employees/{employee}' => Permission::RbacManage, // EmployeeController@update
            'PATCH /api/roles/{role}' => Permission::RbacManage, // RbacController@update
            'PATCH /api/user' => Exempt::self('own profile'), // EmployeeAuthController@updateProfile
            'POST /api/employees' => Permission::RbacManage, // EmployeeController@store
            'POST /api/employees/{employee}/deactivate' => Permission::RbacManage, // EmployeeController@deactivate
            'POST /api/employees/{employee}/invitations' => Permission::RbacManage, // EmployeeController@storeInvitation
            'POST /api/employees/{employee}/reactivate' => Permission::RbacManage, // EmployeeController@reactivate
            'POST /api/employees/{employee}/roles' => Permission::RbacManage, // EmployeeController@storeRole
            'POST /api/logout' => Exempt::self('own session'), // EmployeeAuthController@logout
            'POST /api/roles' => Permission::RbacManage, // RbacController@store
            'POST /api/roles/{role}/archive' => Permission::RbacManage, // RbacController@archive
            'POST /api/roles/{role}/unarchive' => Permission::RbacManage, // RbacController@unarchive
            'POST /api/user/password' => Exempt::self('own password'), // EmployeeAuthController@updatePassword

        ];
    }

    public static function get(string $method, string $uri): Permission|Exempt|null
    {
        $key = strtoupper($method).' /'.ltrim($uri, '/');
        // Normalise api prefix
        if (! str_starts_with($key, strtoupper($method).' /api/')) {
            $key = strtoupper($method).' /api/'.ltrim(preg_replace('#^api/#', '', ltrim($uri, '/')), '/');
        }

        return self::all()[$key] ?? null;
    }
}
