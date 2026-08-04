<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Http\Resources\ReservationCardResource;
use App\Models\Employee;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class ReservationBoardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ReservationManage->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);
        $base = Reservation::query()->visibleTo($employee, Permission::ReservationManage);
        $counts = Reservation::statusCounts($search, clone $base);

        $columns = collect(ReservationStatus::cases())->map(function (ReservationStatus $status) use ($base, $search, $perColumn, $counts) {
            $page = (clone $base)
                ->forBoardColumn($status, $search)
                ->cursorPaginate($perColumn);

            return [
                'status' => $status->value,
                'total' => $counts[$status->value],
                'cards' => ReservationCardResource::collection($page->items())->resolve(),
                'next_cursor' => optional($page->nextCursor())->encode(),
                'has_more' => $page->hasMorePages(),
            ];
        })->all();

        return $this->success(
            ['columns' => $columns],
            'Reservation board retrieved successfully.'
        );
    }

    public function column(Request $request, string $status): JsonResponse
    {
        Gate::authorize(Permission::ReservationManage->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $statusEnum = ReservationStatus::tryFrom($status);

        if ($statusEnum === null) {
            return $this->notFound('Unknown reservation status.');
        }

        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);
        $base = Reservation::query()->visibleTo($employee, Permission::ReservationManage);

        $page = (clone $base)
            ->forBoardColumn($statusEnum, $search)
            ->cursorPaginate($perColumn);

        return $this->success([
            'status' => $statusEnum->value,
            'total' => Reservation::statusCounts($search, clone $base)[$statusEnum->value],
            'cards' => ReservationCardResource::collection($page->items())->resolve(),
            'next_cursor' => optional($page->nextCursor())->encode(),
            'has_more' => $page->hasMorePages(),
        ], 'Reservation board column retrieved successfully.');
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
