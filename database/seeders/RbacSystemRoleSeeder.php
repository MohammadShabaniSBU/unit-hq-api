<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\RolePermission;
use App\Support\Auth\Permission;
use App\Support\Auth\RoleScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RbacSystemRoleSeeder extends Seeder
{
    public function run(): void
    {
        self::upsertSystemRoles();
    }

    /**
     * Idempotent role + permission upsert. Resets system role permission sets
     * to spec; never touches employee_roles grants.
     */
    public static function upsertSystemRoles(): void
    {
        DB::transaction(function (): void {
            foreach (self::definitions() as $definition) {
                $role = Role::query()->firstOrNew(['key' => $definition['key']]);
                $role->fill([
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'scope_level' => $definition['scope'],
                    'is_system' => true,
                    'archived_at' => null,
                ]);
                if ($role->isDirty() || ! $role->exists) {
                    $role->save();
                }

                $desired = array_map(
                    static fn (Permission $p): string => $p->value,
                    $definition['permissions'],
                );
                sort($desired);

                $existing = $role->rolePermissions()->pluck('permission')->map(
                    static fn ($p): string => $p instanceof Permission ? $p->value : (string) $p,
                )->all();
                sort($existing);

                if ($existing === $desired) {
                    continue;
                }

                $role->rolePermissions()->delete();
                $now = now();
                $rows = array_map(
                    static fn (string $permission): array => [
                        'role_id' => $role->id,
                        'permission' => $permission,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $desired,
                );
                if ($rows !== []) {
                    RolePermission::query()->insert($rows);
                }
            }
        });
    }

    /**
     * Explicit permission lists for non-owner roles (for coverage tests).
     *
     * @return array<string, list<Permission>>
     */
    public static function explicitPermissionLists(): array
    {
        $defs = self::definitions();
        $out = [];
        foreach ($defs as $definition) {
            if ($definition['key'] === 'owner') {
                continue;
            }
            $out[$definition['key']] = $definition['permissions'];
        }

        return $out;
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     description: string|null,
     *     scope: RoleScope,
     *     permissions: list<Permission>
     * }>
     */
    private static function definitions(): array
    {
        return [
            [
                'key' => 'owner',
                'label' => 'Owner',
                'description' => 'Full company access including RBAC and credentials.',
                'scope' => RoleScope::Company,
                'permissions' => Permission::cases(),
            ],
            [
                'key' => 'operations_manager',
                'label' => 'Operations manager',
                'description' => 'Company-wide operations without RBAC, legal entities, or credentials.',
                'scope' => RoleScope::Company,
                'permissions' => array_values(array_filter(
                    Permission::cases(),
                    static fn (Permission $p): bool => ! in_array($p, [
                        Permission::RbacManage,
                        Permission::LegalEntityManage,
                        Permission::CredentialManage,
                    ], true),
                )),
            ],
            [
                'key' => 'site_manager',
                'label' => 'Site manager',
                'description' => 'Site-scoped leasing, facility, delinquency, and day-to-day billing.',
                'scope' => RoleScope::Site,
                'permissions' => [
                    Permission::ContactView,
                    Permission::ContactManage,
                    Permission::DealManage,
                    Permission::OfferManage,
                    Permission::OfferSend,
                    Permission::ReservationManage,
                    Permission::ContractView,
                    Permission::ContractSign,
                    Permission::ContractVacate,
                    Permission::ContractTransfer,
                    Permission::ContractRateChange,
                    Permission::UnitView,
                    Permission::UnitManage,
                    Permission::UnitHoldManage,
                    Permission::SiteManage,
                    Permission::CatalogueManage,
                    Permission::DelinquencyView,
                    Permission::DelinquencyAct,
                    Permission::InboxView,
                    Permission::InboxSend,
                    Permission::InboxAssign,
                    Permission::CallPlace,
                    Permission::InvoiceView,
                    Permission::InvoiceIssue,
                    Permission::PaymentRecord,
                    Permission::ReportView,
                    Permission::ActivityView,
                    Permission::AccessView,
                    Permission::EsignSend,
                    Permission::AiSummaryView,
                    Permission::AiSummaryGenerate,
                    Permission::AiAgentUse,
                    Permission::AgentActionApprove,
                    Permission::CopilotVoiceUse,
                ],
            ],
            [
                'key' => 'leasing_agent',
                'label' => 'Leasing agent',
                'description' => 'Site-scoped pipeline and contract signing.',
                'scope' => RoleScope::Site,
                'permissions' => [
                    Permission::ContactView,
                    Permission::ContactManage,
                    Permission::DealManage,
                    Permission::OfferManage,
                    Permission::OfferSend,
                    Permission::ReservationManage,
                    Permission::ContractView,
                    Permission::ContractSign,
                    Permission::UnitView,
                    Permission::InboxView,
                    Permission::InboxSend,
                    Permission::EsignSend,
                ],
            ],
            [
                'key' => 'accountant',
                'label' => 'Accountant',
                'description' => 'Company-wide fiscal and payment operations; no leasing writes.',
                'scope' => RoleScope::Company,
                'permissions' => [
                    Permission::InvoiceView,
                    Permission::InvoiceIssue,
                    Permission::InvoiceRectify,
                    Permission::PaymentView,
                    Permission::PaymentRecord,
                    Permission::PaymentRefund,
                    Permission::BillingRunExecute,
                    Permission::TaxRateManage,
                    Permission::ReportView,
                    Permission::ReportFinancialView,
                    Permission::ActivityView,
                ],
            ],
            [
                'key' => 'read_only',
                'label' => 'Read only',
                'description' => 'View-only access; grantable company-wide or per site.',
                'scope' => RoleScope::Any,
                'permissions' => array_values(array_filter(
                    Permission::cases(),
                    static fn (Permission $p): bool => $p->isView(),
                )),
            ],
        ];
    }
}
