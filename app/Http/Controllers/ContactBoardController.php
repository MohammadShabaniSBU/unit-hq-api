<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ContactLifecycleStatus;
use App\Http\Resources\ContactCardResource;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactBoardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);
        $counts = Contact::statusCounts($search);

        $columns = collect(ContactLifecycleStatus::cases())->map(function (ContactLifecycleStatus $status) use ($search, $perColumn, $counts) {
            $page = Contact::query()
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
        $statusEnum = ContactLifecycleStatus::tryFrom($status);

        if ($statusEnum === null) {
            return $this->notFound('Unknown contact status.');
        }

        $search = $this->boardSearch($request);
        $perColumn = $this->perColumn($request);

        $page = Contact::query()
            ->forBoardColumn($statusEnum, $search)
            ->cursorPaginate($perColumn);

        return $this->success([
            'status' => $statusEnum->value,
            'total' => Contact::statusCounts($search)[$statusEnum->value],
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
