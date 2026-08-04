<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Communications\AircallUserDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * Settings → Communications → Aircall Users: sync, map, unlink.
 */
class AircallUserLinkController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        return $this->success(
            AircallUserDirectory::list(),
            'Aircall users retrieved successfully.'
        );
    }

    public function sync(): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        return $this->success(
            AircallUserDirectory::sync(),
            'Aircall users synced successfully.'
        );
    }

    public function map(Request $request, string $aircallUserId): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
        ]);

        $link = AircallUserDirectory::map($aircallUserId, (int) $validated['employee_id']);

        return $this->success([
            'aircall_user_id' => $link->aircall_user_id,
            'aircall_user_label' => $link->aircall_user_label,
            'employee_id' => $link->employee_id,
            'users' => AircallUserDirectory::list()['users'],
        ], 'Aircall user mapped successfully.');
    }

    public function unlink(string $aircallUserId): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        AircallUserDirectory::unlink($aircallUserId);

        return $this->success(
            AircallUserDirectory::list(),
            'Aircall user unlinked successfully.'
        );
    }
}
