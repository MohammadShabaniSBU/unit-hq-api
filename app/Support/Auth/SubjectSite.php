<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\AccessEvent;
use App\Models\AccessGrant;
use App\Models\AccessPoint;
use App\Models\AccessProviderAccount;
use App\Models\AccessSuspension;
use App\Models\Allocation;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutopayAttempt;
use App\Models\BillingRun;
use App\Models\CommsTriage;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\ContractNotice;
use App\Models\Deal;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicy;
use App\Models\Discount;
use App\Models\Employee;
use App\Models\EsignEnvelope;
use App\Models\EsignProviderAccount;
use App\Models\Insurance;
use App\Models\InsuranceRate;
use App\Models\Invoice;
use App\Models\InvoiceSeries;
use App\Models\LayoutField;
use App\Models\LegalEntity;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Note;
use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentProviderAccount;
use App\Models\PaymentRequest;
use App\Models\Playbook;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteMap;
use App\Models\SiteSenderIdentity;
use App\Models\Task;
use App\Models\TaxRate;
use App\Models\TemplateAsset;
use App\Models\TemplateFamily;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Models\WhatsappTemplate;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the site for authorization of a subject model.
 * null = genuinely company-level. Unmapped classes throw (fail closed).
 */
final class SubjectSite
{
    public static function for(Model $subject): ?Site
    {
        return match ($subject::class) {
            Contact::class => null,
            Deal::class => $subject instanceof Deal ? self::dealSite($subject) : null,
            Offer::class => $subject instanceof Offer ? self::offerSite($subject) : null,
            OfferOption::class => $subject instanceof OfferOption ? self::offerOptionSite($subject) : null,
            Reservation::class => $subject instanceof Reservation ? self::reservationSite($subject) : null,
            Unit::class => $subject instanceof Unit ? self::unitSite($subject) : null,
            Contract::class => $subject instanceof Contract ? self::contractSite($subject) : null,
            ContractItem::class => $subject instanceof ContractItem ? self::contractItemSite($subject) : null,
            Insurance::class => null,
            UnitClassRate::class => $subject instanceof UnitClassRate ? self::unitClassRateSite($subject) : null,
            InsuranceRate::class => $subject instanceof InsuranceRate ? self::insuranceRateSite($subject) : null,
            Task::class => null,
            Note::class => null,
            Invoice::class => $subject instanceof Invoice ? self::invoiceSite($subject) : null,
            Payment::class => $subject instanceof Payment ? self::paymentSite($subject) : null,
            Allocation::class => $subject instanceof Allocation ? self::allocationSite($subject) : null,
            AutopayAttempt::class => self::viaContract(
                $subject instanceof AutopayAttempt ? $subject->contract : null
            ),
            BillingRun::class => null,
            Delinquency::class => self::viaContract(
                $subject instanceof Delinquency ? $subject->contract : null
            ),
            ContractNotice::class => self::viaContract(
                $subject instanceof ContractNotice ? $subject->contract : null
            ),
            AccessPoint::class => $subject instanceof AccessPoint
                ? self::accessPointSite($subject)
                : null,
            AccessSuspension::class => self::viaContract(
                $subject instanceof AccessSuspension ? $subject->contract : null
            ),
            AccessGrant::class => $subject instanceof AccessGrant
                ? self::accessGrantSite($subject)
                : null,
            AccessProviderAccount::class => null,
            AccessEvent::class => $subject instanceof AccessEvent
                ? self::accessEventSite($subject)
                : null,
            UnitHold::class => $subject instanceof UnitHold
                ? self::unitHoldSite($subject)
                : null,
            UnitOccupancy::class => $subject instanceof UnitOccupancy
                ? self::unitOccupancySite($subject)
                : null,
            MessageThread::class => $subject instanceof MessageThread
                ? self::messageThreadSite($subject)
                : null,
            Message::class => $subject instanceof Message ? self::messageSite($subject) : null,
            CommsTriage::class => null,
            TaxRate::class => null,
            Discount::class => null,
            LegalEntity::class => null,
            Role::class => null,
            Employee::class => null,
            Site::class => $subject instanceof Site ? $subject : null,
            SiteMap::class => $subject instanceof SiteMap ? self::siteMapSite($subject) : null,
            SiteSenderIdentity::class => $subject instanceof SiteSenderIdentity
                ? self::siteSenderIdentitySite($subject)
                : null,
            UnitClass::class => null,
            PaymentMethod::class => null,
            PaymentRequest::class => $subject instanceof PaymentRequest
                ? self::viaContract($subject->contract)
                : null,
            PaymentProviderAccount::class => null,
            InvoiceSeries::class => null,
            Automation::class => null,
            AutomationRun::class => null,
            Playbook::class => null,
            AttributeDefinition::class => null,
            AttributeGroup::class => null,
            LayoutField::class => null,
            TemplateFamily::class => null,
            TemplateAsset::class => null,
            WhatsappTemplate::class => null,
            DelinquencyPolicy::class => null,
            CommunicationAccount::class => null,
            EsignProviderAccount::class => null,
            EsignEnvelope::class => $subject instanceof EsignEnvelope
                ? self::viaContract($subject->contract)
                : null,
            default => throw new UnresolvableSubjectSite($subject),
        };
    }

    private static function messageSite(Message $message): ?Site
    {
        $thread = $message->relationLoaded('thread')
            ? $message->thread
            : $message->thread()->first();

        return $thread instanceof MessageThread ? self::messageThreadSite($thread) : null;
    }

    private static function siteMapSite(SiteMap $map): ?Site
    {
        if ($map->site_id === null) {
            return null;
        }

        return $map->relationLoaded('site')
            ? $map->site
            : Site::query()->find($map->site_id);
    }

    private static function siteSenderIdentitySite(SiteSenderIdentity $identity): ?Site
    {
        if ($identity->site_id === null) {
            return null;
        }

        return $identity->relationLoaded('site')
            ? $identity->site
            : Site::query()->find($identity->site_id);
    }

    private static function dealSite(Deal $deal): ?Site
    {
        if ($deal->site_id === null) {
            return null;
        }

        return $deal->relationLoaded('site')
            ? $deal->site
            : Site::query()->find($deal->site_id);
    }

    private static function offerSite(Offer $offer): ?Site
    {
        $deal = $offer->relationLoaded('deal')
            ? $offer->deal
            : Deal::query()->find($offer->deal_id);

        return $deal !== null ? self::dealSite($deal) : null;
    }

    private static function offerOptionSite(OfferOption $option): ?Site
    {
        $offer = $option->relationLoaded('offer')
            ? $option->offer
            : Offer::query()->find($option->offer_id);

        return $offer !== null ? self::offerSite($offer) : null;
    }

    private static function reservationSite(Reservation $reservation): ?Site
    {
        $unit = $reservation->relationLoaded('unit')
            ? $reservation->unit
            : Unit::query()->find($reservation->unit_id);

        return $unit !== null ? self::unitSite($unit) : null;
    }

    private static function unitSite(Unit $unit): ?Site
    {
        return $unit->relationLoaded('site')
            ? $unit->site
            : Site::query()->find($unit->site_id);
    }

    private static function contractSite(Contract $contract): ?Site
    {
        $occupancies = $contract->relationLoaded('occupancies')
            ? $contract->occupancies
            : $contract->occupancies()->with('unit.site')->get();

        $open = $occupancies->first(fn (UnitOccupancy $o): bool => $o->ended_on === null);
        if ($open !== null) {
            return self::occupancyToSite($open);
        }

        $latest = $occupancies->sortByDesc(fn (UnitOccupancy $o): string => (string) $o->started_on)->first();

        return $latest !== null ? self::occupancyToSite($latest) : null;
    }

    private static function contractItemSite(ContractItem $item): ?Site
    {
        $contract = $item->relationLoaded('contract')
            ? $item->contract
            : Contract::query()->find($item->contract_id);

        return $contract !== null ? self::contractSite($contract) : null;
    }

    private static function invoiceSite(Invoice $invoice): ?Site
    {
        if ($invoice->contract_id === null) {
            return null;
        }

        $contract = $invoice->relationLoaded('contract')
            ? $invoice->contract
            : Contract::query()->find($invoice->contract_id);

        return $contract !== null ? self::contractSite($contract) : null;
    }

    private static function paymentSite(Payment $payment): ?Site
    {
        $contract = $payment->relationLoaded('contract')
            ? $payment->contract
            : Contract::query()->find($payment->contract_id);

        return $contract !== null ? self::contractSite($contract) : null;
    }

    private static function allocationSite(Allocation $allocation): ?Site
    {
        $payment = $allocation->relationLoaded('payment')
            ? $allocation->payment
            : Payment::query()->find($allocation->payment_id);

        return $payment !== null ? self::paymentSite($payment) : null;
    }

    private static function accessPointSite(AccessPoint $point): ?Site
    {
        return $point->relationLoaded('site')
            ? $point->site
            : Site::query()->find($point->site_id);
    }

    private static function accessGrantSite(AccessGrant $grant): ?Site
    {
        $point = $grant->relationLoaded('accessPoint')
            ? $grant->accessPoint
            : AccessPoint::query()->find($grant->access_point_id);

        return $point !== null ? self::accessPointSite($point) : null;
    }

    private static function accessEventSite(AccessEvent $event): ?Site
    {
        if ($event->access_point_id !== null) {
            $point = $event->relationLoaded('accessPoint')
                ? $event->accessPoint
                : AccessPoint::query()->find($event->access_point_id);

            if ($point !== null) {
                return self::accessPointSite($point);
            }
        }

        if ($event->access_grant_id !== null) {
            $grant = $event->relationLoaded('accessGrant')
                ? $event->accessGrant
                : AccessGrant::query()->find($event->access_grant_id);

            if ($grant !== null) {
                return self::accessGrantSite($grant);
            }
        }

        return null;
    }

    private static function unitHoldSite(UnitHold $hold): ?Site
    {
        $unit = $hold->relationLoaded('unit')
            ? $hold->unit
            : Unit::query()->find($hold->unit_id);

        return $unit !== null ? self::unitSite($unit) : null;
    }

    private static function unitOccupancySite(UnitOccupancy $occupancy): ?Site
    {
        return self::occupancyToSite($occupancy);
    }

    private static function occupancyToSite(UnitOccupancy $occupancy): ?Site
    {
        $unit = $occupancy->relationLoaded('unit')
            ? $occupancy->unit
            : Unit::query()->with('site')->find($occupancy->unit_id);

        return $unit !== null ? self::unitSite($unit) : null;
    }

    private static function unitClassRateSite(UnitClassRate $rate): ?Site
    {
        return $rate->relationLoaded('site')
            ? $rate->site
            : Site::query()->find($rate->site_id);
    }

    private static function insuranceRateSite(InsuranceRate $rate): ?Site
    {
        return $rate->relationLoaded('site')
            ? $rate->site
            : Site::query()->find($rate->site_id);
    }

    private static function messageThreadSite(MessageThread $thread): ?Site
    {
        $accountId = Message::query()
            ->where('message_thread_id', $thread->id)
            ->whereNotNull('communication_account_id')
            ->orderByDesc('id')
            ->value('communication_account_id');

        if ($accountId !== null) {
            $identity = SiteSenderIdentity::query()
                ->where('account_id', $accountId)
                ->with('site')
                ->first();

            if ($identity?->site !== null) {
                return $identity->site;
            }
        }

        return self::contactMostRecentContractSite((int) $thread->contact_id);
    }

    private static function contactMostRecentContractSite(int $contactId): ?Site
    {
        $contract = Contract::query()
            ->where('contact_id', $contactId)
            ->orderByDesc('id')
            ->first();

        return $contract !== null ? self::contractSite($contract) : null;
    }

    private static function viaContract(?Contract $contract): ?Site
    {
        return $contract !== null ? self::contractSite($contract) : null;
    }
}
