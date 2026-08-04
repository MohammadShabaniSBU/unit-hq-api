<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ContactLifecycleStatus;
use App\Http\Resources\ContactCardResource;
use App\Models\Contact;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class ContactBoardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);
        $base = Contact::query()->visibleTo($employee, Permission::ContactView);
        $counts = Contact::statusCounts($search, clone $base);

        $columns = collect(ContactLifecycleStatus::cases())->map(function (ContactLifecycleStatus $status) use ($base, $search, $perColumn, $counts) {
            $page = (clone $base)
                ->forBoardColumn($status, $search)
                ->cursorPaginate($perColumn);

            return [
                'status' => $status->value,
                'total' => $counts[$status->value],
                'cards' => ContactCardResource::collection($page->items())->resolve(),
                'next_cursor' => optional($page->nextCursor())->encode(),
                'has_more' => $page->hasMorePages(),
            ];
        })->all();

        return $this->success(
            ['columns' => $columns],
            'Contact board retrieved successfully.'
        );
    }

    public function column(Request $request, string $status): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $statusEnum = ContactLifecycleStatus::tryFrom($status);

        if ($statusEnum === null) {
            return $this->notFound('Unknown contact status.');
        }

        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);
        $base = Contact::query()->visibleTo($employee, Permission::ContactView);

        $page = (clone $base)
            ->forBoardColumn($statusEnum, $search)
            ->cursorPaginate($perColumn);

        return $this->success([
            'status' => $statusEnum->value,
            'total' => Contact::statusCounts($search, clone $base)[$statusEnum->value],
            'cards' => ContactCardResource::collection($page->items())->resolve(),
            'next_cursor' => optional($page->nextCursor())->encode(),
            'has_more' => $page->hasMorePages(),
        ], 'Contact board column retrieved successfully.');
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
