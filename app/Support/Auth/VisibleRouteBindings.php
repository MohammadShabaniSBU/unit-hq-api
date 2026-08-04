<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\AccessEvent;
use App\Models\AccessGrant;
use App\Models\AccessPoint;
use App\Models\Contract;
use App\Models\ContractNotice;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Route-model bindings that 404 out-of-scope site-bearing records
 * before policies can 403 (enumeration defence). Contact is excluded (D-RBAC-1).
 *
 * Each param lists every permission that may legitimately resolve the model
 * (e.g. accountants hold InvoiceIssue but not ContractView). Visibility is the
 * union of those grants: company-wide on any → unscoped; site lists merge;
 * none held → unscoped find and let Gate 403 on the action.
 */
final class VisibleRouteBindings
{
    /**
     * @var array<string, array{0: class-string<Model>, 1: list<Permission>}>
     */
    private const BINDINGS = [
        'contract' => [Contract::class, [
            Permission::ContractView,
            Permission::ContractSign,
            Permission::ContractVacate,
            Permission::ContractTransfer,
            Permission::ContractRateChange,
            Permission::InvoiceView,
            Permission::InvoiceIssue,
            Permission::PaymentView,
            Permission::PaymentRecord,
            Permission::DelinquencyView,
            Permission::EsignSend,
            Permission::AccessView,
        ]],
        'reservation' => [Reservation::class, [Permission::ReservationManage]],
        'offer' => [Offer::class, [Permission::OfferManage]],
        'deal' => [Deal::class, [Permission::DealManage]],
        'unit' => [Unit::class, [Permission::UnitView, Permission::UnitManage, Permission::UnitHoldManage]],
        'invoice' => [Invoice::class, [Permission::InvoiceView, Permission::InvoiceIssue, Permission::InvoiceRectify]],
        'payment' => [Payment::class, [Permission::PaymentView, Permission::PaymentRecord, Permission::PaymentRefund]],
        'delinquency' => [Delinquency::class, [Permission::DelinquencyView, Permission::DelinquencyAct]],
        'access_point' => [AccessPoint::class, [Permission::AccessView, Permission::AccessManage]],
        'accessPoint' => [AccessPoint::class, [Permission::AccessView, Permission::AccessManage]],
        'access_grant' => [AccessGrant::class, [Permission::AccessView, Permission::AccessManage]],
        'accessGrant' => [AccessGrant::class, [Permission::AccessView, Permission::AccessManage]],
        'access_event' => [AccessEvent::class, [Permission::AccessView]],
        'accessEvent' => [AccessEvent::class, [Permission::AccessView]],
        'message_thread' => [MessageThread::class, [Permission::InboxView]],
        'messageThread' => [MessageThread::class, [Permission::InboxView]],
        'thread' => [MessageThread::class, [Permission::InboxView]],
        'site' => [Site::class, [Permission::UnitView, Permission::SiteManage, Permission::CatalogueManage]],
        'hold' => [UnitHold::class, [Permission::UnitHoldManage, Permission::UnitView]],
        'unit_hold' => [UnitHold::class, [Permission::UnitHoldManage, Permission::UnitView]],
        'contractNotice' => [ContractNotice::class, [Permission::ContractView, Permission::DelinquencyView]],
        'contract_notice' => [ContractNotice::class, [Permission::ContractView, Permission::DelinquencyView]],
    ];

    public static function register(): void
    {
        foreach (self::BINDINGS as $parameter => [$modelClass, $permissions]) {
            Route::bind($parameter, function (string $value) use ($modelClass, $permissions): Model {
                $query = $modelClass::query()->whereKey($value);

                $user = Auth::user();
                if ($user instanceof Employee && SitePath::hasSitePath($modelClass)) {
                    $siteIds = self::unionSiteIds($user, $permissions);
                    // null = company-wide on at least one permission → no filter.
                    // [] = holds none of the resolve permissions → leave unscoped;
                    //     Gate on the action still 403s.
                    if ($siteIds !== null && $siteIds !== []) {
                        SitePath::constrain($query, $modelClass, $siteIds);
                    }
                }

                $model = $query->first();
                if ($model === null) {
                    throw (new ModelNotFoundException)->setModel($modelClass, [$value]);
                }

                return $model;
            });
        }
    }

    /**
     * @param  list<Permission>  $permissions
     * @return list<int>|null  null = company-wide; [] = nowhere; list = union of sites
     */
    private static function unionSiteIds(Employee $employee, array $permissions): ?array
    {
        $union = [];
        $heldAny = false;

        foreach ($permissions as $permission) {
            $sites = $employee->siteIdsFor($permission);
            if ($sites === []) {
                continue;
            }
            $heldAny = true;
            if ($sites === null) {
                return null;
            }
            foreach ($sites as $id) {
                $union[$id] = true;
            }
        }

        if (! $heldAny) {
            return [];
        }

        $ids = array_map('intval', array_keys($union));
        sort($ids);

        return $ids;
    }
}
