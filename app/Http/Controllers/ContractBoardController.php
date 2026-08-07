<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ContractStatus;
use App\Http\Controllers\Concerns\AppliesPortalSiteFilter;
use App\Http\Resources\ContractCardResource;
use App\Models\Contract;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class ContractBoardController extends Controller
{
    use AppliesPortalSiteFilter;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ContractView->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);
        $base = Contract::query()->visibleTo($employee, Permission::ContractView);
        $this->applyPortalSiteFilter($base, $request, Contract::class, Permission::ContractView);
        $counts = Contract::statusCounts($search, clone $base);

        $columns = collect(ContractStatus::cases())->map(function (ContractStatus $status) use ($base, $search, $perColumn, $counts) {
            $page = (clone $base)
                ->forBoardColumn($status, $search)
                ->cursorPaginate($perColumn);

            return [
                'status' => $status->value,
                'total' => $counts[$status->value],
                'cards' => ContractCardResource::collection($page->items())->resolve(),
                'next_cursor' => optional($page->nextCursor())->encode(),
                'has_more' => $page->hasMorePages(),
            ];
        })->all();

        return $this->success(
            ['columns' => $columns],
            'Contract board retrieved successfully.'
        );
    }

    public function column(Request $request, string $status): JsonResponse
    {
        Gate::authorize(Permission::ContractView->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $statusEnum = ContractStatus::tryFrom($status);

        if ($statusEnum === null) {
            return $this->notFound('Unknown contract status.');
        }

        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);
        $base = Contract::query()->visibleTo($employee, Permission::ContractView);
        $this->applyPortalSiteFilter($base, $request, Contract::class, Permission::ContractView);

        $page = (clone $base)
            ->forBoardColumn($statusEnum, $search)
            ->cursorPaginate($perColumn);

        return $this->success([
            'status' => $statusEnum->value,
            'total' => Contract::statusCounts($search, clone $base)[$statusEnum->value],
            'cards' => ContractCardResource::collection($page->items())->resolve(),
            'next_cursor' => optional($page->nextCursor())->encode(),
            'has_more' => $page->hasMorePages(),
        ], 'Contract board column retrieved successfully.');
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
