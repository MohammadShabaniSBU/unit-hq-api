<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\OfferCardResource;
use App\Models\Employee;
use App\Models\Offer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class OfferBoardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::OfferManage->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);
        $base = Offer::query()->visibleTo($employee, Permission::OfferManage);
        $counts = Offer::statusCounts($search, clone $base);

        $columns = collect(Offer::STATUSES)->map(function (string $status) use ($base, $search, $perColumn, $counts) {
            $page = (clone $base)
                ->forBoardColumn($status, $search)
                ->cursorPaginate($perColumn);

            return [
                'status' => $status,
                'total' => $counts[$status],
                'cards' => OfferCardResource::collection($page->items())->resolve(),
                'next_cursor' => optional($page->nextCursor())->encode(),
                'has_more' => $page->hasMorePages(),
            ];
        })->all();

        return $this->success(
            ['columns' => $columns],
            'Offer board retrieved successfully.'
        );
    }

    public function column(Request $request, string $status): JsonResponse
    {
        Gate::authorize(Permission::OfferManage->value);

        /** @var Employee $employee */
        $employee = $request->user();

        if (! in_array($status, Offer::STATUSES, true)) {
            return $this->notFound('Unknown offer status.');
        }

        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);
        $base = Offer::query()->visibleTo($employee, Permission::OfferManage);

        $page = (clone $base)
            ->forBoardColumn($status, $search)
            ->cursorPaginate($perColumn);

        return $this->success([
            'status' => $status,
            'total' => Offer::statusCounts($search, clone $base)[$status],
            'cards' => OfferCardResource::collection($page->items())->resolve(),
            'next_cursor' => optional($page->nextCursor())->encode(),
            'has_more' => $page->hasMorePages(),
        ], 'Offer board column retrieved successfully.');
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
