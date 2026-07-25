<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\AttributeEntityType;
use App\Support\Filtering\FilterBuilder;
use App\Support\Filtering\FilterSchemaResponder;
use App\Support\Filtering\FilterTreeValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait SearchesWithFilters
{
    protected function respondFilterSchema(AttributeEntityType $entityType): JsonResponse
    {
        return $this->success(
            FilterSchemaResponder::for($entityType),
            'Filter schema retrieved successfully.'
        );
    }

    /**
     * @param  callable(mixed): mixed  $mapResource
     * @param  callable(Builder, Request): void|null  $applyExtras
     */
    protected function searchWithFilters(
        Request $request,
        AttributeEntityType $entityType,
        Builder $query,
        callable $mapResource,
        string $message,
        ?callable $applyExtras = null,
    ): JsonResponse {
        $validated = $request->validate([
            'filter' => ['nullable', 'array'],
            'sort' => ['nullable', 'array'],
            'sort.*.field' => ['required_with:sort', 'string'],
            'sort.*.dir' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ]);

        if ($applyExtras !== null) {
            $applyExtras($query, $request);
        }

        if ($request->filled('search') && method_exists($query->getModel(), 'scopeSearch')) {
            $query->search($request->string('search')->trim()->value());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        $filter = (new FilterTreeValidator($entityType))->validate($validated['filter'] ?? null);

        if ($filter !== null) {
            FilterBuilder::for($entityType)->apply($query, $filter);
        }

        $sorts = $validated['sort'] ?? [];
        FilterBuilder::for($entityType)->applySort($query, $sorts);

        $perPage = min(max((int) ($validated['per_page'] ?? $this->perPage()), 1), 100);

        return $this->paginated(
            $query->paginate($perPage)->through($mapResource),
            $message,
        );
    }
}
