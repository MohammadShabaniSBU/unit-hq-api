<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\TaskCardResource;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class TaskBoardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);

        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);
        $counts = Task::statusCounts($search);

        $columns = collect(Task::STATUSES)->map(function (string $status) use ($search, $perColumn, $counts) {
            $page = Task::query()
                ->forBoardColumn($status, $search)
                ->cursorPaginate($perColumn);

            return [
                'status' => $status,
                'total' => $counts[$status],
                'cards' => TaskCardResource::collection($page->items())->resolve(),
                'next_cursor' => optional($page->nextCursor())->encode(),
                'has_more' => $page->hasMorePages(),
            ];
        })->all();

        return $this->success(
            ['columns' => $columns],
            'Task board retrieved successfully.'
        );
    }

    public function column(Request $request, string $status): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);

        if (! in_array($status, Task::STATUSES, true)) {
            return $this->notFound('Unknown task status.');
        }

        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);

        $page = Task::query()
            ->forBoardColumn($status, $search)
            ->cursorPaginate($perColumn);

        return $this->success([
            'status' => $status,
            'total' => Task::statusCounts($search)[$status],
            'cards' => TaskCardResource::collection($page->items())->resolve(),
            'next_cursor' => optional($page->nextCursor())->encode(),
            'has_more' => $page->hasMorePages(),
        ], 'Task board column retrieved successfully.');
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
