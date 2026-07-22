<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Http\Resources\ReservationCardResource;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationBoardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);
        $counts = Reservation::statusCounts($search);

        $columns = collect(ReservationStatus::cases())->map(function (ReservationStatus $status) use ($search, $perColumn, $counts) {
            $page = Reservation::query()
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
        $statusEnum = ReservationStatus::tryFrom($status);

        if ($statusEnum === null) {
            return $this->notFound('Unknown reservation status.');
        }

        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);

        $page = Reservation::query()
            ->forBoardColumn($statusEnum, $search)
            ->cursorPaginate($perColumn);

        return $this->success([
            'status' => $statusEnum->value,
            'total' => Reservation::statusCounts($search)[$statusEnum->value],
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
