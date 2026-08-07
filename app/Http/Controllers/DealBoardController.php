<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DealStatus;
use App\Http\Controllers\Concerns\AppliesPortalSiteFilter;
use App\Http\Resources\DealCardResource;
use App\Models\Deal;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class DealBoardController extends Controller
{
    use AppliesPortalSiteFilter;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::DealManage->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);
        $base = Deal::query()->visibleTo($employee, Permission::DealManage);
        $this->applyPortalSiteFilter($base, $request, Deal::class, Permission::DealManage);
        $counts = Deal::statusCounts($search, clone $base);

        $columns = collect(DealStatus::cases())->map(function (DealStatus $status) use ($base, $search, $perColumn, $counts) {
            $page = (clone $base)
                ->forBoardColumn($status, $search)
                ->cursorPaginate($perColumn);

            return [
                'status' => $status->value,
                'total' => $counts[$status->value],
                'cards' => DealCardResource::collection($page->items())->resolve(),
                'next_cursor' => optional($page->nextCursor())->encode(),
                'has_more' => $page->hasMorePages(),
            ];
        })->all();

        return $this->success(
            ['columns' => $columns],
            'Deal board retrieved successfully.'
        );
    }

    public function column(Request $request, string $status): JsonResponse
    {
        Gate::authorize(Permission::DealManage->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $statusEnum = DealStatus::tryFrom($status);

        if ($statusEnum === null) {
            return $this->notFound('Unknown deal status.');
        }

        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);
        $base = Deal::query()->visibleTo($employee, Permission::DealManage);
        $this->applyPortalSiteFilter($base, $request, Deal::class, Permission::DealManage);

        $page = (clone $base)
            ->forBoardColumn($statusEnum, $search)
            ->cursorPaginate($perColumn);

        return $this->success([
            'status' => $statusEnum->value,
            'total' => Deal::statusCounts($search, clone $base)[$statusEnum->value],
            'cards' => DealCardResource::collection($page->items())->resolve(),
            'next_cursor' => optional($page->nextCursor())->encode(),
            'has_more' => $page->hasMorePages(),
        ], 'Deal board column retrieved successfully.');
    }

    private function boardSearch(Request $request): ?string
    {
        $search = $request->string('search')->trim()->toString();

        return $search !== '' ? $search : null;
    }

    private function perColumn(Request $request): int
    {
        return max(1, min((int) $request->integer('per_column', 30), 100));
    }
}
