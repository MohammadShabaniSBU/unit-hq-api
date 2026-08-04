<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Contract;
use App\Models\Employee;
use App\Policies\ContractPolicy;
use App\Support\Auth\Permission;
use App\Support\Auth\SystemActor;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Explicit policy registry (no discovery) + system-actor short-circuit.
 * Task 03 appends to POLICIES as controllers are wired.
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
    ];

    /**
     * Model-less ability gates keyed by Permission value.
     *
     * @var list<Permission>
     */
    public const ABILITY_GATES = [
        Permission::BillingRunExecute,
        Permission::BillingSettingsManage,
        Permission::SettingsManage,
        Permission::RbacManage,
        Permission::CatalogueManage,
        Permission::TaxRateManage,
        Permission::LegalEntityManage,
        Permission::ReportView,
        Permission::ReportFinancialView,
        Permission::ActivityView,
        Permission::CredentialManage,
        Permission::TemplateManage,
        Permission::AutomationView,
        Permission::AutomationManage,
        Permission::PlaybookManage,
    ];

    public function boot(): void
    {
        Gate::before(function ($actor) {
            return $actor instanceof SystemActor ? true : null;
        });

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        foreach (self::ABILITY_GATES as $permission) {
            Gate::define($permission->value, function ($actor) use ($permission): bool {
                if (! $actor instanceof Employee) {
                    return false;
                }

                return $actor->allowsPermission($permission);
            });
        }
    }
}
