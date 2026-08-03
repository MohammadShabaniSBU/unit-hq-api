<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Enums\AccessSuspensionLiftReason;
use App\Enums\AutopayAttemptStatus;
use App\Enums\AutopayAttemptTrigger;
use App\Enums\ChargeType;
use App\Enums\ContactChannelType;
use App\Enums\ContactLifecycleStatus;
use App\Enums\ContactSource;
use App\Enums\ContractDocumentStatus;
use App\Enums\ContractEndedReason;
use App\Enums\ContractItemChangeReason;
use App\Enums\ContractStatus;
use App\Enums\DealStatus;
use App\Enums\DelinquencyCureTrigger;
use App\Enums\DelinquencyStepAction;
use App\Enums\DelinquencyStepTrigger;
use App\Enums\DepositSettlementOutcome;
use App\Enums\PaymentRequestStatus;
use App\Enums\PlaybookKind;
use App\Enums\TaxIdType;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Enums\TransferPricingMode;
use App\Jobs\EvaluateDelinquency;
use App\Models\AccessPoint;
use App\Models\AccessSuspension;
use App\Models\AutomationRun;
use App\Models\AutopayAttempt;
use App\Models\CallWrapup;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\ContractItem;
use App\Models\ContractTransfer;
use App\Models\Deal;
use App\Models\Delinquency;
use App\Models\DepositSettlement;
use App\Models\DepositSettlementLine;
use App\Models\Employee;
use App\Models\EsignEnvelope;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Offer;
use App\Models\OfferDelivery;
use App\Models\OfferOption;
use App\Models\PaymentMethod;
use App\Models\PaymentRequest;
use App\Models\Playbook;
use App\Models\Setting;
use App\Models\Site;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Models\UnitOccupancy;
use App\Models\WhatsappTemplate;
use App\Support\Access\AccessSync;
use App\Support\Billing\BillingMath;
use App\Support\Billing\CurrencyGuard;
use App\Support\Billing\ResolvesContractItemPrice;
use App\Support\Billing\TransferSettlement;
use App\Support\Billing\VacateSettlement;
use App\Support\Communications\Channel;
use App\Support\Communications\Exceptions\SendRefused;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\Messages\WhatsAppSessionMessage;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\EmailSender;
use App\Support\Communications\Senders\SmsSender;
use App\Support\Communications\Senders\WhatsAppSender;
use App\Support\Contracts\ContractSigning;
use App\Support\Contracts\ContractTransition;
use App\Support\Contracts\ScheduleRateChange;
use App\Support\Delinquency\DelinquencyEngine;
use App\Support\Delinquency\DelinquencyLifecycle;
use App\Support\Delinquency\DelinquencyState;
use App\Support\Delinquency\Overlock;
use App\Support\ESign\EnvelopeOrchestrator;
use App\Support\Fiscal\InvoiceIssuer;
use App\Support\Fiscal\TaxId;
use App\Support\Fiscal\TaxResolver;
use App\Support\Occupancy\HoldGuard;
use App\Support\Occupancy\OccupancyGuard;
use App\Support\Payments\ProviderAccountResolver;
use App\Support\RecordsActivity;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoWorld;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Story-shaped wrappers around real Support / injector entry points.
 */
final class JourneySupport
{
    public static function createContact(
        DemoWorld $world,
        string $handle,
        string $firstName,
        string $lastName,
        array $attrs = [],
    ): Contact {
        $email = $attrs['email'] ?? strtolower($handle).'@demo.unit-hq.test';
        $phone = $attrs['phone'] ?? '+34600'.str_pad((string) (abs(crc32($handle)) % 1000000), 6, '0', STR_PAD_LEFT);

        // Ordinary invoices require fiscalComplete(); move-in / quarterly batches
        // routinely exceed the €400 simplified limit (same pattern as OccupancySeeder).
        [$defaultTaxId, $defaultTaxIdType] = self::demoFiscalId(
            $handle,
            (string) ($attrs['billing_country_code'] ?? 'ES'),
        );

        $contact = Contact::query()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'company' => $attrs['company'] ?? null,
            'status' => $attrs['status'] ?? ContactLifecycleStatus::Prospect,
            'source' => $attrs['source'] ?? ContactSource::Import,
            'source_detail' => $attrs['source_detail'] ?? 'demo_cast',
            'assigned_to' => $attrs['assigned_to'] ?? Employee::query()->value('id'),
            'billing_name' => $attrs['billing_name'] ?? "{$firstName} {$lastName}",
            'tax_id' => $attrs['tax_id'] ?? $defaultTaxId,
            'tax_id_type' => $attrs['tax_id_type'] ?? $defaultTaxIdType,
            'billing_address_line1' => $attrs['billing_address_line1'] ?? 'Calle Demo 1',
            'billing_city' => $attrs['billing_city'] ?? 'Madrid',
            'billing_postal_code' => $attrs['billing_postal_code'] ?? '28001',
            'billing_country_code' => $attrs['billing_country_code'] ?? 'ES',
        ]);

        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Email,
            'value' => $email,
            'is_primary' => true,
            'opted_in' => true,
        ]);
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Sms,
            'value' => $phone,
            'is_primary' => true,
            'opted_in' => true,
        ]);
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Phone,
            'value' => $phone,
            'is_primary' => true,
            'opted_in' => true,
        ]);
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Whatsapp,
            'value' => $phone,
            'is_primary' => true,
            'opted_in' => true,
        ]);

        $world->remember("{$handle}.contact", $contact);
        $world->remember("{$handle}.phone", $phone);
        $world->remember("{$handle}.email", $email);

        return $contact;
    }

    public static function openDeal(
        DemoWorld $world,
        string $handle,
        Site $site,
        DealStatus|string $status = DealStatus::Qualified,
    ): Deal {
        $contact = $world->contact("{$handle}.contact");
        $statusEnum = $status instanceof DealStatus ? $status : DealStatus::from($status);

        $deal = Deal::query()->create([
            'contact_id' => $contact->id,
            'site_id' => $site->id,
            'status' => $statusEnum,
            'expected_move_in' => now()->addDays(14)->toDateString(),
        ]);

        $world->remember("{$handle}.deal", $deal);
        $world->remember("{$handle}.site", $site);

        return $deal;
    }

    public static function vacantUnit(Site $site, string $unitClassCode = 'SS4'): Unit
    {
        $class = UnitClass::query()->where('code', $unitClassCode)->firstOrFail();
        $today = SiteClock::today($site);

        $units = Unit::query()
            ->where('site_id', $site->id)
            ->where('unit_class_id', $class->id)
            ->orderBy('unit_number')
            ->get();

        foreach ($units as $unit) {
            try {
                OccupancyGuard::assertVacant($unit->id, $today, null);
                HoldGuard::assertUnheld($unit->id, $today, null);

                return $unit;
            } catch (ValidationException) {
                continue;
            }
        }

        throw new RuntimeException("No vacant {$unitClassCode} unit at site {$site->code}.");
    }

    public static function catalogueAmount(Unit $unit): string
    {
        $rate = UnitClassRate::query()
            ->with('price')
            ->where('unit_class_id', $unit->unit_class_id)
            ->where('site_id', $unit->site_id)
            ->firstOrFail();

        $price = $rate->price;
        if ($price === null) {
            throw new RuntimeException("No catalogue price for unit {$unit->unit_number}.");
        }

        return BillingMath::round2((string) $price->amount);
    }

    /**
     * Walk-in immediate sign (pending or active depending on move-in vs site today).
     */
    public static function walkInSign(
        DemoWorld $world,
        string $handle,
        Unit $unit,
        string $startDate,
        ?string $moveInDate = null,
        ?string $amount = null,
        ?float $deposit = null,
        string $mode = 'immediate',
    ): Contract {
        $contact = $world->contact("{$handle}.contact");
        $deal = $world->has("{$handle}.deal") ? $world->get("{$handle}.deal") : null;
        $amount ??= self::catalogueAmount($unit);
        $moveIn = CarbonImmutable::parse($moveInDate ?? $startDate)->startOfDay();
        $site = $unit->site()->with('country')->firstOrFail();
        $today = SiteClock::today($site);
        $billing = Setting::billing();
        $leasing = Setting::leasing();
        $createdBy = Employee::query()->value('id');

        $remote = $mode === 'remote';
        $status = $remote
            ? ContractStatus::AwaitingSignature
            : ($moveIn->toDateString() > $today->toDateString()
                ? ContractStatus::Pending
                : ContractStatus::Active);

        $contract = DB::transaction(function () use (
            $contact,
            $deal,
            $unit,
            $startDate,
            $moveIn,
            $amount,
            $deposit,
            $billing,
            $leasing,
            $createdBy,
            $remote,
            $status,
            $site,
        ): Contract {
            $contract = Contract::query()->create([
                'contact_id' => $contact->id,
                'deal_id' => $deal instanceof Deal ? $deal->id : null,
                'start_date' => $startDate,
                'status' => $status->value,
                'notice_period_days' => $leasing->defaultNoticePeriodDays,
                'move_out_settlement' => $billing->moveOutSettlement,
                'transfer_billing' => $billing->transferBilling,
                'signed_at' => null,
                'billing_interval' => $billing->defaultBillingInterval,
                'billing_interval_count' => $billing->defaultBillingIntervalCount,
                'billing_anchor_model' => $billing->billingAnchorModel,
                'proration_method' => $billing->prorationMethod,
                'move_in_date' => $moveIn->toDateString(),
                'deposit_amount' => $deposit ?? $billing->defaultDepositAmount,
                'currency' => $site->currency ?: 'EUR',
            ]);

            $taxRate = TaxResolver::resolve(
                null,
                $unit->unitClass?->tax_rate_code ?? UnitClass::query()->find($unit->unit_class_id)?->tax_rate_code,
                $site,
                $moveIn,
            );

            $price = ResolvesContractItemPrice::forSigning(
                'unit',
                (int) $unit->id,
                BillingMath::round2($amount),
                (int) $unit->site_id,
                $createdBy !== null ? (int) $createdBy : null,
            );

            $item = $contract->items()->create([
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'price_id' => $price->id,
                'effective_from' => $moveIn->toDateString(),
                'effective_to' => null,
                'tax_rate_id' => $taxRate?->id,
                'tax_rate_snapshot' => $taxRate?->rate,
            ]);
            $item->load('price');

            $agreed = CurrencyGuard::assertItemsAgree(collect([$item]));
            $contract->forceFill(['currency' => $agreed])->save();

            if ($remote) {
                ContractSigning::writeSignatureHolds($contract, collect([$item]), $createdBy !== null ? (int) $createdBy : null);
            } else {
                ContractSigning::complete(
                    $contract,
                    null,
                    $createdBy !== null ? (int) $createdBy : null,
                    now(),
                );
            }

            return $contract->fresh(['items.price', 'contact', 'occupancies']) ?? $contract;
        });

        $world->remember("{$handle}.contract", $contract);
        $world->remember("{$handle}.unit", $unit);

        if ($deal instanceof Deal && ! $remote) {
            $deal->forceFill(['status' => DealStatus::ClosedWon])->save();
        }

        if (! $remote) {
            $contact->forceFill(['status' => ContactLifecycleStatus::Tenant])->save();
        }

        return $contract;
    }

    public static function sendEnvelope(
        DemoWorld $world,
        string $handle,
        ?CarbonImmutable $expiresAt = null,
    ): EsignEnvelope {
        $contract = self::contract($world, $handle);
        self::ensureDraftDocument($contract);

        $envelope = app(EnvelopeOrchestrator::class)->send(
            $contract,
            null,
            $expiresAt,
            Employee::query()->first(),
        );

        $world->remember("{$handle}.envelope", $envelope);

        return $envelope;
    }

    public static function ensureDraftDocument(Contract $contract): ContractDocument
    {
        $existing = ContractDocument::query()
            ->where('contract_id', $contract->id)
            ->where('status', ContractDocumentStatus::Draft)
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $family = TemplateFamily::query()
            ->where('channel', TemplateChannel::Document)
            ->where('purpose', TemplatePurpose::Contract)
            ->firstOrFail();
        $variant = TemplateVariant::query()
            ->where('template_family_id', $family->id)
            ->firstOrFail();

        $path = 'contracts/demo-'.$contract->id.'-'.Str::lower(Str::random(6)).'.pdf';
        Storage::disk('local')->put($path, FakeESignPdf::BYTES);

        return ContractDocument::query()->create([
            'contract_id' => $contract->id,
            'template_family_id' => $family->id,
            'template_variant_id' => $variant->id,
            'rendered_at' => now(),
            'pdf_path' => $path,
            'sha256' => hash('sha256', FakeESignPdf::BYTES),
            'status' => ContractDocumentStatus::Draft,
        ]);
    }

    public static function markSteadyPayer(DemoWorld $world, string $handle): void
    {
        $world->remember("{$handle}.payer", 'steady');
    }

    public static function markLatePayer(DemoWorld $world, string $handle, int $lagDays): void
    {
        $lagDays = max(1, min(30, $lagDays));
        $world->remember("{$handle}.payer", 'late:'.$lagDays);
    }

    public static function startMissingPayments(DemoWorld $world, string $handle): void
    {
        $world->remember("{$handle}.payer", 'missing');
    }

    /**
     * Daily standing order: pay open due balance for steady / late payers.
     */
    public static function tickStandingOrders(DemoWorld $world): void
    {
        foreach ($world->payerEntries() as $entry) {
            $handle = $entry['handle'];
            $mode = $entry['mode'];
            if (! $world->has("{$handle}.contract")) {
                continue;
            }
            if ($mode === 'steady') {
                self::payOpenBalance($world, $handle, lagDays: 0);
            } elseif (str_starts_with($mode, 'late:')) {
                $lag = (int) substr($mode, strlen('late:'));
                self::payOpenBalance($world, $handle, lagDays: $lag);
            }
            // missing: no auto-pay
        }
    }

    public static function payOpenBalance(DemoWorld $world, string $handle, int $lagDays = 0): ?PaymentRequest
    {
        $contract = self::contract($world, $handle)->fresh(['charges']);
        $status = $contract->status instanceof ContractStatus
            ? $contract->status
            : ContractStatus::from((string) $contract->status);
        // Vacate/cancel close the live unit item; ProviderAccountResolver needs it.
        if (! in_array($status, [
            ContractStatus::Active,
            ContractStatus::NoticeGiven,
            ContractStatus::Pending,
        ], true)) {
            return null;
        }

        $today = now()->toDateString();
        $open = $contract->charges
            ->filter(static function (Charge $c) use ($today, $lagDays): bool {
                if (bccomp($c->openAmount(), '0.00', 2) <= 0) {
                    return false;
                }
                if ($c->due_date === null) {
                    return $lagDays === 0;
                }
                $due = $c->due_date instanceof \Carbon\CarbonInterface
                    ? $c->due_date->toDateString()
                    : (string) $c->due_date;

                $payAfter = CarbonImmutable::parse($due)->addDays($lagDays)->toDateString();

                return $payAfter <= $today;
            });

        if ($open->isEmpty()) {
            return null;
        }

        $amount = '0.00';
        $chargeIds = [];
        foreach ($open as $charge) {
            $amount = bcadd($amount, $charge->openAmount(), 2);
            $chargeIds[] = (int) $charge->id;
        }
        $amount = BillingMath::round2($amount);
        if (bccomp($amount, '0', 2) <= 0) {
            return null;
        }

        $account = ProviderAccountResolver::forContract($contract);
        $request = PaymentRequest::query()->create([
            'token' => Str::random(64),
            'contract_id' => $contract->id,
            'payment_provider_account_id' => $account->id,
            'charge_ids' => $chargeIds,
            'amount' => $amount,
            'currency' => $contract->currency,
            'status' => PaymentRequestStatus::Pending,
            'expires_at' => now()->addDays(7),
            'created_by' => Employee::query()->value('id'),
        ]);

        $world->stripe()->paymentSucceeded(
            paymentIntentId: 'pi_demo_'.$handle.'_'.$request->id.'_'.Str::lower(Str::random(4)),
            amount: $amount,
            currency: (string) $contract->currency,
            metadata: ['payment_request_id' => (string) $request->id],
        );

        $world->remember("{$handle}.last_payment_request", $request->fresh());

        return $request->fresh();
    }

    public static function createPaymentLink(DemoWorld $world, string $handle): PaymentRequest
    {
        $contract = self::contract($world, $handle)->fresh(['charges']);
        $open = $contract->charges
            ->filter(static fn (Charge $c): bool => bccomp($c->openAmount(), '0.00', 2) > 0);

        $amount = '0.00';
        $chargeIds = [];
        foreach ($open as $charge) {
            $amount = bcadd($amount, $charge->openAmount(), 2);
            $chargeIds[] = (int) $charge->id;
        }
        $amount = BillingMath::round2($amount);
        if (bccomp($amount, '0', 2) <= 0) {
            // Seed a small rent charge so the link is meaningful.
            $charge = Charge::query()->create([
                'contract_id' => $contract->id,
                'charge_type' => ChargeType::Rent,
                'net_amount' => '50.00',
                'tax_amount' => '0.00',
                'amount' => '50.00',
                'currency' => $contract->currency,
                'due_date' => now()->toDateString(),
                'description' => 'Demo payment-link charge',
            ]);
            $chargeIds = [(int) $charge->id];
            $amount = '50.00';
        }

        $account = ProviderAccountResolver::forContract($contract);
        $request = PaymentRequest::query()->create([
            'token' => Str::random(64),
            'contract_id' => $contract->id,
            'payment_provider_account_id' => $account->id,
            'charge_ids' => $chargeIds,
            'amount' => $amount,
            'currency' => $contract->currency,
            'status' => PaymentRequestStatus::Pending,
            'expires_at' => now()->addDays(7),
            'created_by' => Employee::query()->value('id'),
        ]);

        $world->remember("{$handle}.payment_request", $request);

        return $request;
    }

    public static function payViaLink(DemoWorld $world, string $handle): void
    {
        /** @var PaymentRequest $request */
        $request = $world->get("{$handle}.payment_request");
        $request->refresh();

        $world->stripe()->paymentSucceeded(
            paymentIntentId: 'pi_demo_link_'.$request->id,
            amount: BillingMath::round2((string) $request->amount),
            currency: (string) $request->currency,
            metadata: ['payment_request_id' => (string) $request->id],
        );
    }

    public static function enableAutopay(DemoWorld $world, string $handle): PaymentMethod
    {
        $contact = $world->contact("{$handle}.contact");
        $contract = self::contract($world, $handle);
        $pmId = 'pm_demo_'.$handle.'_'.Str::lower(Str::random(6));

        $world->stripe()->setupSucceeded($contact->id, $pmId);
        $method = PaymentMethod::query()->where('stripe_pm_id', $pmId)->firstOrFail();

        $contract->forceFill([
            'autopay_enabled' => true,
            'payment_method_id' => $method->id,
        ])->save();

        $world->remember("{$handle}.payment_method", $method);

        return $method;
    }

    public static function failAutopay(DemoWorld $world, string $handle, string $code = 'insufficient_funds'): AutopayAttempt
    {
        $contract = self::contract($world, $handle)->fresh(['charges', 'paymentMethod']);
        /** @var PaymentMethod $method */
        $method = $world->get("{$handle}.payment_method");

        $open = $contract->charges
            ->filter(static fn (Charge $c): bool => bccomp($c->openAmount(), '0.00', 2) > 0);
        $amount = '0.00';
        $chargeIds = [];
        foreach ($open as $charge) {
            $amount = bcadd($amount, $charge->openAmount(), 2);
            $chargeIds[] = (int) $charge->id;
        }
        if (bccomp($amount, '0', 2) <= 0) {
            $charge = Charge::query()->create([
                'contract_id' => $contract->id,
                'charge_type' => ChargeType::Rent,
                'net_amount' => '80.00',
                'tax_amount' => '0.00',
                'amount' => '80.00',
                'currency' => $contract->currency,
                'due_date' => now()->subDay()->toDateString(),
                'description' => 'Demo autopay target',
            ]);
            $chargeIds = [(int) $charge->id];
            $amount = '80.00';
        }
        $amount = BillingMath::round2($amount);
        $piId = 'pi_demo_fail_'.$handle.'_'.Str::lower(Str::random(4));

        $attempt = AutopayAttempt::query()->create([
            'contract_id' => $contract->id,
            'payment_method_id' => $method->id,
            'charge_ids' => $chargeIds,
            'amount' => $amount,
            'currency' => $contract->currency,
            'stripe_payment_intent_id' => $piId,
            'status' => AutopayAttemptStatus::Pending,
            'triggered_by' => AutopayAttemptTrigger::Manual,
            'attempted_at' => now(),
        ]);

        $world->stripe()->paymentFailed(
            paymentIntentId: $piId,
            code: $code,
            amount: $amount,
            currency: (string) $contract->currency,
            metadata: ['autopay_attempt_id' => (string) $attempt->id],
        );

        $world->remember("{$handle}.last_autopay_attempt", $attempt->fresh());

        return $attempt->fresh() ?? $attempt;
    }

    public static function transfer(
        DemoWorld $world,
        string $handle,
        Unit $destination,
        string $transferDate,
        TransferPricingMode $mode = TransferPricingMode::DestinationRate,
        ?string $reason = null,
    ): ContractTransfer {
        $contract = self::contract($world, $handle);

        $row = null;
        DB::transaction(function () use ($contract, $destination, $transferDate, $mode, $reason, &$row): void {
            $contract = Contract::query()
                ->with(['items.price', 'charges', 'unitItem.item.site'])
                ->lockForUpdate()
                ->findOrFail($contract->id);

            ContractTransition::assertTransferable($contract);

            $date = CarbonImmutable::parse($transferDate)->startOfDay();
            $originItem = ContractItem::query()
                ->with('price')
                ->where('contract_id', $contract->id)
                ->where('item_type', 'unit')
                ->whereNull('effective_to')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->firstOrFail();

            $occupancyEndsOn = null;
            $status = $contract->status instanceof ContractStatus
                ? $contract->status
                : ContractStatus::from((string) $contract->status);
            if ($status === ContractStatus::NoticeGiven && $contract->scheduled_move_out_on !== null) {
                $occupancyEndsOn = CarbonImmutable::parse($contract->scheduled_move_out_on)->startOfDay();
            }

            OccupancyGuard::assertVacant($destination->id, $date, $occupancyEndsOn);
            HoldGuard::assertUnheld($destination->id, $date, $occupancyEndsOn);

            $plan = TransferSettlement::compute($contract, $destination, $date, $mode, $originItem);
            $billedThroughBefore = $contract->billedThrough();
            $depositBefore = (string) $contract->deposit_amount;

            $occupancy = UnitOccupancy::query()
                ->where('contract_id', $contract->id)
                ->orderByDesc('started_on')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->firstOrFail();

            $occupancy->forceFill([
                'ended_on' => $date->toDateString(),
                'ended_reason' => ContractEndedReason::TransferredOut->value,
            ])->save();

            $originItem->forceFill(['effective_to' => $date->toDateString()])->save();

            $newItem = ContractItem::query()->create([
                'contract_id' => $contract->id,
                'item_type' => 'unit',
                'item_id' => $destination->id,
                'price_id' => $plan['destination_item']['price_id'],
                'discount_id' => $originItem->discount_id,
                'base_rate' => $originItem->base_rate,
                'discount_ends_at' => $originItem->discount_ends_at,
                'tax_rate_id' => $plan['destination_item']['tax_rate_id'],
                'tax_rate_snapshot' => $plan['destination_item']['tax_rate_snapshot'],
                'declared_goods_value' => $originItem->declared_goods_value,
                'description' => $originItem->description,
                'effective_from' => $date->toDateString(),
                'effective_to' => null,
                'supersedes_id' => $originItem->id,
                'change_reason' => ContractItemChangeReason::Transfer,
            ]);

            UnitOccupancy::query()->create([
                'unit_id' => $destination->id,
                'contract_id' => $contract->id,
                'contract_item_id' => $newItem->id,
                'started_on' => $date->toDateString(),
                'ended_on' => $occupancyEndsOn?->toDateString(),
                'created_by' => Employee::query()->value('id'),
            ]);

            $chargeIdsBefore = Charge::query()->where('contract_id', $contract->id)->pluck('id');
            self::persistTransferPlan($contract, $plan, $newItem, $date);

            $newCharges = Charge::query()
                ->where('contract_id', $contract->id)
                ->whereNotIn('id', $chargeIdsBefore)
                ->get();

            $contract->load(['contact', 'unitItem.item.site.country', 'unitItem.item.site.legalEntity']);
            $split = InvoiceIssuer::splitSettlementCharges($contract, $newCharges);
            $actorId = Employee::query()->value('id');
            InvoiceIssuer::issueCreditsForContract(
                $contract,
                $split['credits'],
                InvoiceIssuer::REASON_TRANSFER_CREDIT,
                $actorId !== null ? (int) $actorId : null,
            );
            InvoiceIssuer::issue($contract, $split['debits'], null, $actorId !== null ? (int) $actorId : null);

            if (bccomp((string) $plan['deposit']['new_deposit_amount'], $depositBefore, 2) !== 0) {
                $contract->forceFill([
                    'deposit_amount' => $plan['deposit']['new_deposit_amount'],
                ])->save();
            }

            $row = ContractTransfer::query()->create([
                'contract_id' => $contract->id,
                'from_unit_id' => (int) $originItem->item_id,
                'to_unit_id' => $destination->id,
                'from_contract_item_id' => $originItem->id,
                'to_contract_item_id' => $newItem->id,
                'transfer_date' => $date->toDateString(),
                'pricing_mode' => $mode,
                'reason' => $reason,
                'created_by' => $actorId,
            ]);

            RecordsActivity::core('contract.transferred', $contract, [
                'from_unit_id' => (int) $originItem->item_id,
                'to_unit_id' => $destination->id,
                'transfer_date' => $date->toDateString(),
                'pricing_mode' => $mode->value,
                'reason' => $reason,
            ]);

            $contract->refresh();
            if ($contract->billedThrough() !== $billedThroughBefore) {
                $contract->forceFill(['billed_through' => $billedThroughBefore])->save();
            }

            AccessSync::nudge((int) $contract->id);
        });

        $world->remember("{$handle}.contract", $contract->fresh());
        $world->remember("{$handle}.unit", $destination);
        $world->remember("{$handle}.transfer", $row);

        return $row;
    }

    public static function giveNotice(DemoWorld $world, string $handle, string $scheduledMoveOutOn): Contract
    {
        $contract = self::contract($world, $handle);

        DB::transaction(function () use ($contract, $scheduledMoveOutOn): void {
            $contract = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            ContractTransition::assert($contract, ContractStatus::NoticeGiven);

            $from = $contract->status instanceof ContractStatus
                ? $contract->status
                : ContractStatus::from((string) $contract->status);

            $unit = Unit::query()->findOrFail(
                (int) ContractItem::query()
                    ->where('contract_id', $contract->id)
                    ->where('item_type', 'unit')
                    ->whereNull('effective_to')
                    ->value('item_id')
            );
            $today = SiteClock::today($unit->site);
            $scheduled = CarbonImmutable::parse($scheduledMoveOutOn)->startOfDay();

            $contract->forceFill([
                'status' => ContractStatus::NoticeGiven,
                'notice_given_on' => $today->toDateString(),
                'scheduled_move_out_on' => $scheduled->toDateString(),
            ])->save();

            $occupancy = UnitOccupancy::query()
                ->where('contract_id', $contract->id)
                ->orderByDesc('started_on')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->firstOrFail();
            $occupancy->forceFill(['ended_on' => $scheduled->toDateString()])->save();

            RecordsActivity::core('contract.status_changed', $contract, [
                'from' => $from->value,
                'to' => ContractStatus::NoticeGiven->value,
            ]);
            RecordsActivity::core('contract.notice_given', $contract, [
                'notice_given_on' => $today->toDateString(),
                'scheduled_move_out_on' => $scheduled->toDateString(),
            ]);
            AccessSync::nudge((int) $contract->id);
        });

        $fresh = $contract->fresh() ?? $contract;
        $world->remember("{$handle}.contract", $fresh);

        return $fresh;
    }

    /**
     * @param  list<array{amount: string, reason: string, tax_rate_id?: int|null}>  $deductions
     */
    public static function vacate(
        DemoWorld $world,
        string $handle,
        string $moveOutOn,
        DepositSettlementOutcome $outcome = DepositSettlementOutcome::Released,
        array $deductions = [],
        ?ContractEndedReason $endedReason = null,
    ): Contract {
        $contract = self::contract($world, $handle);
        $endedReason ??= ContractEndedReason::Vacated;

        DB::transaction(function () use ($contract, $moveOutOn, $outcome, $deductions, $endedReason): void {
            $contract = Contract::query()
                ->with(['items.price', 'charges', 'unitItem.item.site'])
                ->lockForUpdate()
                ->findOrFail($contract->id);

            $from = $contract->status instanceof ContractStatus
                ? $contract->status
                : ContractStatus::from((string) $contract->status);

            $moveOut = CarbonImmutable::parse($moveOutOn)->startOfDay();
            $noticeGivenOn = $contract->notice_given_on !== null
                ? CarbonImmutable::parse($contract->notice_given_on)->startOfDay()
                : $moveOut;

            if ($from === ContractStatus::Active) {
                $unitId = (int) ContractItem::query()
                    ->where('contract_id', $contract->id)
                    ->where('item_type', 'unit')
                    ->whereNull('effective_to')
                    ->value('item_id');
                $unit = Unit::query()->with('site')->findOrFail($unitId);
                $today = SiteClock::today($unit->site);
                $contract->forceFill([
                    'notice_given_on' => $today->toDateString(),
                    'scheduled_move_out_on' => $moveOut->toDateString(),
                ]);
                $noticeGivenOn = $today;
            }

            $plan = VacateSettlement::compute($contract, $moveOut, $outcome, $deductions, $noticeGivenOn);
            ContractTransition::assert($contract, ContractStatus::Ended);
            self::assertOverlockAllowsVacate($contract);

            $billedThroughBefore = $contract->billedThrough();

            $contract->forceFill([
                'status' => ContractStatus::Ended,
                'move_out_on' => $moveOut->toDateString(),
                'ended_reason' => $endedReason,
                'notice_given_on' => $noticeGivenOn->toDateString(),
                'scheduled_move_out_on' => $contract->scheduled_move_out_on
                    ?? $moveOut->toDateString(),
            ])->save();

            $occupancy = UnitOccupancy::query()
                ->where('contract_id', $contract->id)
                ->orderByDesc('started_on')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->firstOrFail();
            $occupancy->forceFill([
                'ended_on' => $moveOut->toDateString(),
                'ended_reason' => $endedReason->value,
            ])->save();

            ContractItem::query()
                ->where('contract_id', $contract->id)
                ->whereNull('effective_to')
                ->update(['effective_to' => $moveOut->toDateString()]);

            $chargeIdsBefore = Charge::query()->where('contract_id', $contract->id)->pluck('id');
            self::persistVacatePlan($contract, $plan, $moveOut);

            $newCharges = Charge::query()
                ->where('contract_id', $contract->id)
                ->whereNotIn('id', $chargeIdsBefore)
                ->get();

            $contract->load(['contact', 'unitItem.item.site.country', 'unitItem.item.site.legalEntity']);
            $split = InvoiceIssuer::splitSettlementCharges($contract, $newCharges);
            $actorId = Employee::query()->value('id');
            InvoiceIssuer::issueCreditsForContract(
                $contract,
                $split['credits'],
                InvoiceIssuer::REASON_VACATE_SETTLEMENT,
                $actorId !== null ? (int) $actorId : null,
            );
            InvoiceIssuer::issue($contract, $split['debits'], null, $actorId !== null ? (int) $actorId : null);

            RecordsActivity::core('contract.status_changed', $contract, [
                'from' => $from->value,
                'to' => ContractStatus::Ended->value,
            ]);
            RecordsActivity::core('contract.ended', $contract, [
                'move_out_on' => $moveOut->toDateString(),
                'ended_reason' => $endedReason->value,
            ]);

            $contract->refresh();
            if ($contract->billedThrough() !== $billedThroughBefore) {
                $contract->forceFill(['billed_through' => $billedThroughBefore])->save();
            }

            $id = (int) $contract->id;
            AccessSuspension::lift($contract, AccessSuspensionLiftReason::Vacated);
            AccessSync::nudge($id);
            DB::afterCommit(static function () use ($id): void {
                EvaluateDelinquency::dispatch($id, DelinquencyCureTrigger::Vacated);
            });
        });

        $fresh = $contract->fresh() ?? $contract;
        $world->remember("{$handle}.contract", $fresh);
        // Stop standing-order autopay — live unit item is closed on vacate.
        self::startMissingPayments($world, $handle);
        $contact = $world->contact("{$handle}.contact");
        $stillOccupying = Contract::query()
            ->where('contact_id', $contact->id)
            ->whereIn('status', [
                ContractStatus::Active->value,
                ContractStatus::Pending->value,
                ContractStatus::NoticeGiven->value,
                ContractStatus::AwaitingSignature->value,
            ])
            ->exists();
        if (! $stillOccupying) {
            $contact->forceFill(['status' => ContactLifecycleStatus::PastTenant])->save();
        }

        return $fresh;
    }

    public static function writeOff(DemoWorld $world, string $handle, string $reason = 'Demo write-off'): void
    {
        $contract = self::contract($world, $handle);
        $case = Delinquency::query()
            ->where('contract_id', $contract->id)
            ->open()
            ->firstOrFail();

        DB::transaction(function () use ($case, $contract, $reason): void {
            $contract = $contract->fresh(['charges.allocations']) ?? $contract;
            $overdue = DelinquencyState::overdueCharges($contract);
            $total = '0.00';
            foreach ($overdue as $charge) {
                $total = bcadd($total, $charge->openAmount(), 2);
            }
            $total = BillingMath::round2($total);

            if (bccomp($total, '0', 2) <= 0) {
                (new DelinquencyEngine)->evaluateContract($contract, DelinquencyCureTrigger::WriteOff);

                return;
            }

            $today = DelinquencyState::siteToday($contract);
            $net = BillingMath::round2(bcmul($total, '-1', 2));
            $charge = Charge::query()->create([
                'contract_id' => $contract->id,
                'charge_type' => ChargeType::WriteOff,
                'net_amount' => $net,
                'tax_amount' => '0.00',
                'amount' => $net,
                'currency' => $contract->currency,
                'due_date' => $today->subDay()->toDateString(),
                'description' => 'Write-off: '.$reason,
            ]);

            DelinquencyLifecycle::recordStep(
                delinquency: $case,
                action: DelinquencyStepAction::WriteOff,
                trigger: DelinquencyStepTrigger::Manual,
                executedOn: $today->toDateString(),
                charge: $charge,
                detail: ['reason' => $reason, 'amount' => $net],
                createdBy: Employee::query()->first(),
            );

            (new DelinquencyEngine)->evaluateContract(
                $contract->fresh() ?? $contract,
                DelinquencyCureTrigger::WriteOff,
            );
        });
    }

    public static function scheduleRateChange(
        DemoWorld $world,
        string $handle,
        string $newAmount,
        string $effectiveDate,
        bool $acknowledgeShortNotice = true,
    ): void {
        $contract = self::contract($world, $handle);
        $itemId = (int) ContractItem::query()
            ->where('contract_id', $contract->id)
            ->where('item_type', 'unit')
            ->whereNull('effective_to')
            ->orderByDesc('id')
            ->value('id');

        $result = ScheduleRateChange::run(
            $contract,
            $itemId,
            BillingMath::round2($newAmount),
            $effectiveDate,
            Employee::query()->first(),
            $acknowledgeShortNotice,
            $acknowledgeShortNotice ? 'Demo cast rate change' : null,
        );

        $world->remember("{$handle}.rate_change", $result);
    }

    public static function cancelContract(DemoWorld $world, string $handle): Contract
    {
        $contract = self::contract($world, $handle);
        DB::transaction(static function () use ($contract): void {
            ContractSigning::cancel($contract);
        });

        $fresh = $contract->fresh() ?? $contract;
        $world->remember("{$handle}.contract", $fresh);
        self::startMissingPayments($world, $handle);

        return $fresh;
    }

    public static function sendSms(DemoWorld $world, string $handle, string $body): Message
    {
        $contact = $world->contact("{$handle}.contact");
        $phone = (string) $world->get("{$handle}.phone");
        $site = self::siteFor($world, $handle);

        $result = app(SmsSender::class)->send(
            new SmsMessage($phone, $body),
            $site,
            $contact,
            SendContext::manual(SendClass::Transactional),
        );

        $message = Message::query()->findOrFail($result->messageId);
        $world->remember("{$handle}.sms_thread", $message->thread);
        $world->remember("{$handle}.last_sms", $message);

        return $message;
    }

    public static function sendEmail(DemoWorld $world, string $handle, string $subject, string $body): Message
    {
        $contact = $world->contact("{$handle}.contact");
        $email = (string) $world->get("{$handle}.email");
        $site = self::siteFor($world, $handle);

        $result = app(EmailSender::class)->send(
            new EmailMessage(
                to: [new EmailAddress($email)],
                subject: $subject,
                html: '<p>'.e($body).'</p>',
                text: $body,
            ),
            $site,
            $contact,
            SendContext::manual(SendClass::Transactional),
        );

        $message = Message::query()->findOrFail($result->messageId);
        $world->remember("{$handle}.email_thread", $message->thread);
        $world->remember("{$handle}.last_email", $message);

        return $message;
    }

    public static function sendOfferEmail(DemoWorld $world, string $handle, ?string $body = null): Message
    {
        /** @var Offer $offer */
        $offer = $world->get("{$handle}.offer");
        $contact = $world->contact("{$handle}.contact");
        $email = (string) $world->get("{$handle}.email");
        $site = self::siteFor($world, $handle);
        $body ??= 'Aquí tiene su oferta de Unit HQ. Ábrala con el enlace seguro del correo.';

        $delivery = OfferDelivery::query()->create([
            'offer_id' => $offer->id,
            'channel' => 'email',
            'recipient_address' => $email,
            'sent_at' => now(),
            'delivery_status' => 'queued',
        ]);

        $result = app(EmailSender::class)->send(
            new EmailMessage(
                to: [new EmailAddress($email)],
                subject: 'Su oferta de Unit HQ',
                html: '<p>'.e($body).'</p>',
                text: $body,
            ),
            $site,
            $contact,
            SendContext::offer($delivery),
            $offer->deal_id,
        );

        $message = Message::query()->findOrFail($result->messageId);
        $world->remember("{$handle}.email_thread", $message->thread);
        $world->remember("{$handle}.last_email", $message);
        $world->remember("{$handle}.offer_delivery", $delivery->fresh() ?? $delivery);

        return $message;
    }

    public static function sendWhatsAppTemplate(DemoWorld $world, string $handle, string $body): Message
    {
        $contact = $world->contact("{$handle}.contact");
        $phone = (string) $world->get("{$handle}.phone");
        $site = self::siteFor($world, $handle);

        WhatsappTemplate::query()->firstOrCreate(
            [
                'communication_account_id' => $world->whatsappAccount()->id,
                'name' => 'demo_cast_hello',
                'language' => 'es',
            ],
            [
                'category' => 'UTILITY',
                'status' => WhatsappTemplate::STATUS_APPROVED,
                'body' => 'Hola {{1}}, le escribimos desde Unit HQ.',
                'variables' => ['1'],
            ],
        );

        $result = app(WhatsAppSender::class)->sendResolvedTemplate(
            $phone,
            'demo_cast_hello',
            [$contact->first_name ?? 'cliente'],
            $site,
            $contact,
            SendContext::manual(SendClass::Marketing),
        );

        $message = Message::query()->findOrFail($result->messageId);
        // Preserve the cast narrative body on the recorded message preview when useful.
        if ($body !== '' && $message->body_text !== $body) {
            $message->forceFill(['body_text' => $body])->save();
        }
        $world->remember("{$handle}.wa_thread", $message->thread);

        return $message;
    }

    public static function sendWhatsAppSession(DemoWorld $world, string $handle, string $body): ?Message
    {
        $contact = $world->contact("{$handle}.contact");
        $phone = (string) $world->get("{$handle}.phone");
        $site = self::siteFor($world, $handle);
        $thread = $world->has("{$handle}.wa_thread")
            ? $world->get("{$handle}.wa_thread")
            : null;

        if (! $thread instanceof MessageThread) {
            $thread = MessageThread::query()
                ->where('contact_id', $contact->id)
                ->where('channel', Channel::Whatsapp)
                ->latest('id')
                ->firstOrFail();
        }

        try {
            $result = app(WhatsAppSender::class)->sendSession(
                new WhatsAppSessionMessage($phone, $body),
                $site,
                $contact,
                SendContext::manual(SendClass::Transactional),
                $thread,
            );
        } catch (SendRefused $e) {
            // Cast days advance in 24h steps; Meta window is last_inbound+1 day exclusive.
            if ($e->reasonKey === 'whatsapp.window_closed') {
                return null;
            }

            throw $e;
        }

        $message = Message::query()->findOrFail($result->messageId);
        $world->remember("{$handle}.wa_thread", $message->thread);

        return $message;
    }

    public static function inboundSms(DemoWorld $world, string $handle, string $body): void
    {
        $phone = (string) $world->get("{$handle}.phone");
        $world->inbound()->sms($phone, $body);
    }

    public static function inboundWhatsApp(DemoWorld $world, string $handle, string $body): void
    {
        $phone = (string) $world->get("{$handle}.phone");
        $world->inbound()->whatsapp($phone, $body);
    }

    public static function inboundEmail(DemoWorld $world, string $handle, string $body): void
    {
        $email = (string) $world->get("{$handle}.email");
        $thread = $world->has("{$handle}.email_thread")
            ? $world->get("{$handle}.email_thread")
            : null;
        $world->inbound()->email(
            $email,
            $body,
            $thread instanceof MessageThread ? $thread : null,
        );
    }

    public static function hardBounceLastEmail(DemoWorld $world, string $handle): void
    {
        /** @var Message $message */
        $message = $world->get("{$handle}.last_email");
        $world->delivery()->event($message, 'hard_bounce');
    }

    public static function recordCallWrapup(
        DemoWorld $world,
        string $handle,
        string $disposition,
        ?string $note = null,
        string $direction = 'outbound',
    ): CallWrapup {
        $contact = $world->contact("{$handle}.contact");
        $phone = (string) $world->get("{$handle}.phone");
        $employee = Employee::query()->orderBy('id')->first();

        if ($direction === 'inbound') {
            $message = $disposition === 'voicemail_left'
                ? $world->aircall()->voicemail($phone)
                : $world->aircall()->answeredInbound($phone, $note);
        } else {
            $intent = $world->aircall()->requestIntent($contact, $phone, $employee);
            $message = $world->aircall()->answeredOutbound($phone, $intent);
        }

        $wrapup = $world->aircall()->wrapup($message, $disposition, $note, $employee);

        $world->remember("{$handle}.call_wrapup", $wrapup);
        $world->remember("{$handle}.call_message", $message);

        return $wrapup;
    }

    public static function doorDenied(DemoWorld $world, string $handle): void
    {
        $contact = $world->contact("{$handle}.contact");
        $point = AccessPoint::query()->first();
        $pointRef = $point?->provider_point_id ?? 'fake-gate-1';

        $world->access()->doorEvent($pointRef, 'denied', $contact);
    }

    public static function createOffer(
        DemoWorld $world,
        string $handle,
        Site $site,
        string $unitClassCode = 'SS4',
        string $status = 'sent',
    ): Offer {
        $contact = $world->contact("{$handle}.contact");
        $deal = $world->has("{$handle}.deal")
            ? $world->get("{$handle}.deal")
            : self::openDeal($world, $handle, $site);

        $class = UnitClass::query()->where('code', $unitClassCode)->firstOrFail();
        $rate = UnitClassRate::query()
            ->where('unit_class_id', $class->id)
            ->where('site_id', $site->id)
            ->firstOrFail();

        $offer = Offer::query()->create([
            'deal_id' => $deal->id,
            'contact_id' => $contact->id,
            'token' => Str::random(64),
            'status' => $status,
            'sent_at' => in_array($status, ['sent', 'viewed', 'accepted'], true) ? now() : null,
            'expires_at' => now()->addDays(14),
        ]);

        OfferOption::query()->create([
            'offer_id' => $offer->id,
            'unit_class_rate_id' => $rate->id,
            'unit_id' => Unit::query()
                ->where('site_id', $site->id)
                ->where('unit_class_id', $class->id)
                ->value('id'),
            'label' => $class->label,
            'display_order' => 0,
        ]);

        $world->remember("{$handle}.offer", $offer);
        $world->remember("{$handle}.site", $site);

        if (in_array($status, ['sent', 'viewed', 'accepted'], true)) {
            self::sendOfferEmail($world, $handle);
        }

        return $offer;
    }

    public static function markOfferViewed(DemoWorld $world, string $handle): void
    {
        /** @var Offer $offer */
        $offer = $world->get("{$handle}.offer");
        $offer->forceFill([
            'status' => 'viewed',
            'first_viewed_at' => now(),
        ])->save();
    }

    /**
     * Remember the live lead-chase run created by the deal.object_created trigger.
     * $completedSteps is retained for call-site compatibility; progress is time-driven.
     */
    public static function enrolLeadChase(DemoWorld $world, string $handle, int $completedSteps = 2): void
    {
        unset($completedSteps);

        /** @var Deal $deal */
        $deal = $world->get("{$handle}.deal");

        $playbook = Playbook::query()
            ->where('kind', PlaybookKind::LeadChase)
            ->where('is_active', true)
            ->whereNotNull('automation_id')
            ->orderBy('id')
            ->first();

        if ($playbook === null) {
            throw new RuntimeException('No active compiled lead-chase playbook on the demo stage.');
        }

        $run = AutomationRun::query()
            ->where('automation_id', $playbook->automation_id)
            ->where('subject_type', 'deal')
            ->where('subject_id', $deal->id)
            ->latest('id')
            ->first();

        if ($run === null) {
            throw new RuntimeException(
                "Lead-chase run missing for deal {$deal->id} — trigger should have enrolled on create."
            );
        }

        $world->remember("{$handle}.lead_chase_run", $run);
    }

    public static function siteFor(DemoWorld $world, string $handle): Site
    {
        if ($world->has("{$handle}.site")) {
            $site = $world->get("{$handle}.site");
            if ($site instanceof Site) {
                return $site;
            }
        }

        if ($world->has("{$handle}.deal")) {
            $deal = $world->get("{$handle}.deal");
            if ($deal instanceof Deal) {
                $site = Site::query()->find((int) $deal->site_id);
                if ($site !== null) {
                    return $world->remember("{$handle}.site", $site);
                }
            }
        }

        return $world->site('madrid');
    }

    public static function contract(DemoWorld $world, string $handle): Contract
    {
        $value = $world->get("{$handle}.contract");
        if (! $value instanceof Contract) {
            throw new RuntimeException("Handle {$handle}.contract is not a Contract.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private static function persistTransferPlan(
        Contract $contract,
        array $plan,
        ContractItem $newItem,
        CarbonImmutable $transferDate,
    ): void {
        if ($plan['credit'] !== null) {
            $line = $plan['credit'];
            Charge::query()->create([
                'contract_id' => $contract->id,
                'contract_item_id' => $line['contract_item_id'],
                'charge_type' => $line['charge_type'],
                'period_start' => $line['period_start'],
                'period_end' => $line['period_end'],
                'net_amount' => $line['net'],
                'tax_rate_snapshot' => $line['tax_rate_snapshot'],
                'tax_amount' => $line['tax'],
                'amount' => $line['gross'],
                'currency' => $contract->currency,
                'due_date' => $transferDate->toDateString(),
                'description' => $line['adjusts_charge_id'] !== null
                    ? $line['description'].' #'.$line['adjusts_charge_id']
                    : $line['description'],
                'reversal_of_charge_id' => $line['adjusts_charge_id'],
            ]);
        }

        if ($plan['debit'] !== null) {
            $line = $plan['debit'];
            Charge::query()->create([
                'contract_id' => $contract->id,
                'contract_item_id' => $newItem->id,
                'charge_type' => $line['charge_type'],
                'period_start' => $line['period_start'],
                'period_end' => $line['period_end'],
                'net_amount' => $line['net'],
                'tax_rate_snapshot' => $line['tax_rate_snapshot'],
                'tax_amount' => $line['tax'],
                'amount' => $line['gross'],
                'currency' => $contract->currency,
                'due_date' => $transferDate->toDateString(),
                'description' => $line['description'],
            ]);
        }

        if ($plan['deposit']['charge'] !== null) {
            $line = $plan['deposit']['charge'];
            Charge::query()->create([
                'contract_id' => $contract->id,
                'charge_type' => $line['charge_type'],
                'net_amount' => $line['net'],
                'tax_rate_snapshot' => $line['tax_rate_snapshot'],
                'tax_amount' => $line['tax'],
                'amount' => $line['gross'],
                'currency' => $contract->currency,
                'due_date' => $transferDate->toDateString(),
                'description' => $line['description'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private static function persistVacatePlan(Contract $contract, array $plan, CarbonImmutable $moveOutOn): void
    {
        foreach ($plan['item_lines'] as $line) {
            Charge::query()->create([
                'contract_id' => $contract->id,
                'contract_item_id' => $line['contract_item_id'],
                'charge_type' => $line['charge_type'],
                'period_start' => $line['period_start'],
                'period_end' => $line['period_end'],
                'net_amount' => $line['net'],
                'tax_rate_snapshot' => $line['tax_rate_snapshot'],
                'tax_amount' => $line['tax'],
                'amount' => $line['gross'],
                'currency' => $contract->currency,
                'due_date' => $moveOutOn->toDateString(),
                'description' => $line['adjusts_charge_id'] !== null
                    ? $line['description'].' #'.$line['adjusts_charge_id']
                    : $line['description'],
                'reversal_of_charge_id' => $line['adjusts_charge_id'],
            ]);
        }

        $depositPlan = $plan['deposit'];
        if (bccomp((string) $depositPlan['deposit_amount'], '0', 2) === 0) {
            return;
        }

        $settlement = DepositSettlement::query()->create([
            'contract_id' => $contract->id,
            'outcome' => $depositPlan['outcome'],
            'deposit_amount' => $depositPlan['deposit_amount'],
            'refunded_amount' => $depositPlan['refunded_amount'],
            'currency' => $contract->currency,
            'payout_status' => $depositPlan['payout_status'],
            'paid_at' => null,
            'created_by' => Employee::query()->value('id'),
        ]);

        foreach ($depositPlan['lines'] as $line) {
            $charge = Charge::query()->create([
                'contract_id' => $contract->id,
                'charge_type' => $line['charge_type'],
                'net_amount' => $line['net'],
                'tax_rate_snapshot' => $line['tax_rate_snapshot'],
                'tax_amount' => $line['tax'],
                'amount' => $line['gross'],
                'currency' => $contract->currency,
                'due_date' => $moveOutOn->toDateString(),
                'description' => $line['description'].': '.$line['reason'],
            ]);

            if ($line['kind'] === 'deduction' || $line['kind'] === 'refund') {
                $amount = (string) $line['gross'];
                if (bccomp($amount, '0', 2) < 0) {
                    $amount = bcmul($amount, '-1', 2);
                }

                DepositSettlementLine::query()->create([
                    'deposit_settlement_id' => $settlement->id,
                    'charge_id' => $charge->id,
                    'amount' => $amount,
                    'currency' => $contract->currency,
                    'reason' => $line['reason'],
                    'created_at' => now(),
                ]);
            }
        }
    }

    private static function assertOverlockAllowsVacate(Contract $contract): void
    {
        $open = Delinquency::query()
            ->where('contract_id', $contract->id)
            ->open()
            ->with('policy')
            ->first();

        if ($open === null) {
            return;
        }

        $live = Overlock::liveHolds($open);
        if ($live->isEmpty()) {
            return;
        }

        $autoRelease = $open->policy?->auto_release_overlock ?? true;
        if (! $autoRelease) {
            throw ValidationException::withMessages([
                'contract' => [__('errors.contracts.overlock_pending_release')],
            ]);
        }

        Overlock::release($open, 'cure');
    }

    /**
     * Deterministic, checksum-valid tax IDs so demo contacts are fiscalComplete().
     *
     * @return array{0: string, 1: TaxIdType}
     */
    private static function demoFiscalId(string $handle, string $countryCode): array
    {
        $seed = abs(crc32($handle));

        return match (strtoupper($countryCode)) {
            'FR' => [self::demoSiren($seed), TaxIdType::Siren],
            'GB' => [sprintf('%08d', $seed % 100000000), TaxIdType::UkCrn],
            default => [self::demoNif($seed), TaxIdType::Nif],
        };
    }

    private static function demoNif(int $seed): string
    {
        $number = $seed % 100000000;
        $letters = 'TRWAGMYFPDXBNJZSQVHLCKE';

        return sprintf('%08d%s', $number, $letters[$number % 23]);
    }

    private static function demoSiren(int $seed): string
    {
        $body = sprintf('%08d', $seed % 100000000);
        for ($check = 0; $check <= 9; $check++) {
            $candidate = $body.(string) $check;
            if (TaxId::validate($candidate, TaxIdType::Siren->value)) {
                return $candidate;
            }
        }

        return '732829320'; // known-valid SIREN fallback
    }
}

/**
 * Minimal PDF bytes for draft contract documents in the demo cast.
 */
final class FakeESignPdf
{
    public const BYTES = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
}
