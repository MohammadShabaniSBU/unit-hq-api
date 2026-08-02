<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccessEventType;
use App\Http\Resources\AccessEventResource;
use App\Models\AccessEvent;
use App\Models\AccessPoint;
use App\Models\Contact;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessEventController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        return $this->list($request);
    }

    public function forContact(Request $request, Contact $contact): JsonResponse
    {
        return $this->list($request, contactId: (int) $contact->id);
    }

    public function forUnit(Request $request, Unit $unit): JsonResponse
    {
        $pointIds = AccessPoint::query()
            ->where('unit_id', $unit->id)
            ->pluck('id')
            ->all();

        return $this->list($request, accessPointIds: $pointIds);
    }

    /**
     * @param  list<int>|null  $accessPointIds
     */
    private function list(
        Request $request,
        ?int $contactId = null,
        ?array $accessPointIds = null,
    ): JsonResponse {
        $validated = $request->validate([
            'cursor' => ['sometimes', 'nullable', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'site_id' => ['sometimes', 'nullable', 'integer', 'exists:sites,id'],
            'access_point_id' => ['sometimes', 'nullable', 'integer', 'exists:access_points,id'],
            'contact_id' => ['sometimes', 'nullable', 'integer', 'exists:contacts,id'],
            'denied_only' => ['sometimes', 'boolean'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        $query = AccessEvent::query()
            ->with([
                'accessPoint:id,label,point_type,site_id,unit_id',
                'contact:id,first_name,last_name',
                'accessGrant:id,contract_id,access_point_id,contact_id',
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($contactId !== null) {
            $query->where('contact_id', $contactId);
        } elseif (isset($validated['contact_id'])) {
            $query->where('contact_id', (int) $validated['contact_id']);
        }

        if ($accessPointIds !== null) {
            if ($accessPointIds === []) {
                return $this->cursorPaginated([], null, 'Access events retrieved successfully.');
            }
            $query->whereIn('access_point_id', $accessPointIds);
        }

        if (isset($validated['access_point_id'])) {
            $query->where('access_point_id', (int) $validated['access_point_id']);
        }

        if (isset($validated['site_id'])) {
            $siteId = (int) $validated['site_id'];
            $query->where(function (Builder $q) use ($siteId): void {
                $q->whereHas('accessPoint', fn (Builder $p) => $p->where('site_id', $siteId));
            });
        }

        if ($request->boolean('denied_only')) {
            $query->where('event_type', AccessEventType::Denied->value);
        }

        if (isset($validated['from'])) {
            $query->where('occurred_at', '>=', $validated['from']);
        }

        if (isset($validated['to'])) {
            $query->where('occurred_at', '<=', $validated['to']);
        }

        $page = $query->cursorPaginate(
            $perPage,
            ['*'],
            'cursor',
            $validated['cursor'] ?? null,
        );

        $data = collect($page->items())
            ->map(fn (AccessEvent $event) => AccessEventResource::make($event)->resolve())
            ->values()
            ->all();

        return $this->cursorPaginated(
            $data,
            optional($page->nextCursor())->encode(),
            'Access events retrieved successfully.',
        );
    }
}
