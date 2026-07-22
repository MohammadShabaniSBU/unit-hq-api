<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DealStatus;
use App\Http\Resources\DealCardResource;
use App\Models\Deal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealBoardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);
        $counts = Deal::statusCounts($search);

        $columns = collect(DealStatus::cases())->map(function (DealStatus $status) use ($search, $perColumn, $counts) {
            $page = Deal::query()
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
        $statusEnum = DealStatus::tryFrom($status);

        if ($statusEnum === null) {
            return $this->notFound('Unknown deal status.');
        }

        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);

        $page = Deal::query()
            ->forBoardColumn($statusEnum, $search)
            ->cursorPaginate($perColumn);

        return $this->success([
            'status' => $statusEnum->value,
            'total' => Deal::statusCounts($search)[$statusEnum->value],
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
