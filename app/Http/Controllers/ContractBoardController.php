<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ContractStatus;
use App\Http\Resources\ContractCardResource;
use App\Models\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractBoardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);
        $counts = Contract::statusCounts($search);

        $columns = collect(ContractStatus::cases())->map(function (ContractStatus $status) use ($search, $perColumn, $counts) {
            $page = Contract::query()
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
        $statusEnum = ContractStatus::tryFrom($status);

        if ($statusEnum === null) {
            return $this->notFound('Unknown contract status.');
        }

        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);

        $page = Contract::query()
            ->forBoardColumn($statusEnum, $search)
            ->cursorPaginate($perColumn);

        return $this->success([
            'status' => $statusEnum->value,
            'total' => Contract::statusCounts($search)[$statusEnum->value],
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
