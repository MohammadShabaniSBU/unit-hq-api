<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AccessEvent;
use App\Models\AccessGrant;
use App\Models\AccessPoint;
use App\Models\AgentConversation;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\CopilotConversation;
use App\Models\Deal;
use App\Models\Delinquency;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\MessageThread;
use App\Models\Offer;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitHold;
use App\Policies\AccessEventPolicy;
use App\Policies\AccessGrantPolicy;
use App\Policies\AccessPointPolicy;
use App\Policies\AgentConversationPolicy;
use App\Policies\ContactPolicy;
use App\Policies\ContractPolicy;
use App\Policies\ConversationPolicy;
use App\Policies\DealPolicy;
use App\Policies\DelinquencyPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\MessageThreadPolicy;
use App\Policies\OfferPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\ReservationPolicy;
use App\Policies\SitePolicy;
use App\Policies\UnitHoldPolicy;
use App\Policies\UnitPolicy;
use App\Support\Auth\DenialContext;
use App\Support\Auth\Permission;
use App\Support\Auth\SubjectSite;
use App\Support\Auth\SystemActor;
use App\Support\Auth\VisibleRouteBindings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Explicit policy registry (no discovery) + permission ability gates + system-actor short-circuit.
 */
class AuthServiceProvider extends ServiceProvider
{
    /**
     * Explicit model → policy map. Enumerable for task 06 coverage.
     *
     * @var array<class-string, class-string>
     */
    public const POLICIES = [
        Contract::class => ContractPolicy::class,
        Contact::class => ContactPolicy::class,
        Deal::class => DealPolicy::class,
        Offer::class => OfferPolicy::class,
        Reservation::class => ReservationPolicy::class,
        Unit::class => UnitPolicy::class,
        UnitHold::class => UnitHoldPolicy::class,
        Site::class => SitePolicy::class,
        Invoice::class => InvoicePolicy::class,
        Payment::class => PaymentPolicy::class,
        Delinquency::class => DelinquencyPolicy::class,
        MessageThread::class => MessageThreadPolicy::class,
        AccessPoint::class => AccessPointPolicy::class,
        AccessGrant::class => AccessGrantPolicy::class,
        AccessEvent::class => AccessEventPolicy::class,
        CopilotConversation::class => ConversationPolicy::class,
        AgentConversation::class => AgentConversationPolicy::class,
    ];

    public function boot(): void
    {
        Gate::before(function ($actor) {
            return $actor instanceof SystemActor ? true : null;
        });

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        VisibleRouteBindings::register();

        // Every Permission is an ability gate. Optional Model subject carries site scope.
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, function ($actor, mixed $subject = null) use ($permission): bool {
                if (! $actor instanceof Employee) {
                    return false;
                }

                $site = $subject instanceof Model ? SubjectSite::for($subject) : null;

                if ($actor->allowsPermission($permission, $site)) {
                    return true;
                }

                DenialContext::set($permission, $site?->id);

                return false;
            });
        }
    }
}
