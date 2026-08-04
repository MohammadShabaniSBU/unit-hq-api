<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\EmployeeRole;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * At least one company-wide owner grant must always exist.
 * Call assertCanRevoke inside the deleting hook and again (with FOR UPDATE)
 * inside any controller transaction that revokes grants.
 */
final class OwnerFloor
{
    /**
     * Lock all company-wide owner grants for the remainder of the transaction.
     */
    public static function lockCompanyOwnerGrants(): void
    {
        $ownerRoleId = self::ownerRoleId();
        if ($ownerRoleId === null) {
            return;
        }

        EmployeeRole::query()
            ->where('role_id', $ownerRoleId)
            ->whereNull('site_id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @throws ValidationException
     */
    public static function assertCanRevoke(EmployeeRole $grant): void
    {
        $ownerRoleId = self::ownerRoleId();
        if ($ownerRoleId === null) {
            return;
        }

        if ((int) $grant->role_id !== $ownerRoleId || $grant->site_id !== null) {
            return;
        }

        // lockForUpdate cannot wrap aggregate COUNT on Postgres — lock rows, then count.
        $remaining = EmployeeRole::query()
            ->where('role_id', $ownerRoleId)
            ->whereNull('site_id')
            ->where('id', '!=', $grant->id)
            ->lockForUpdate()
            ->get(['id'])
            ->count();

        if ($remaining === 0) {
            throw ValidationException::withMessages([
                'role' => [__('errors.rbac.last_owner')],
            ]);
        }
    }

    /**
     * Revoke a grant inside a transaction with owner-floor locking.
     *
     * @throws ValidationException
     */
    public static function revoke(EmployeeRole $grant): void
    {
        DB::transaction(function () use ($grant): void {
            self::lockCompanyOwnerGrants();
            self::assertCanRevoke($grant);
            $grant->delete();
        });
    }

    private static function ownerRoleId(): ?int
    {
        $id = Role::query()->where('key', 'owner')->value('id');

        return $id !== null ? (int) $id : null;
    }
}
