<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LogChannel;
use App\Http\Resources\AdConversionDeliveryResource;
use App\Jobs\DeliverAdConversions;
use App\Models\AdConversionDelivery;
use App\Support\Advertising\AdDeliverySkipReason;
use App\Support\Advertising\AdDeliveryStatus;
use App\Support\Advertising\ConversionEventKey;
use App\Support\Auth\Permission;
use App\Support\RecordsActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdConversionDeliveryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingView->value);

        $validated = $this->filters($request);

        return $this->paginated(
            $this->filtered($validated)
                ->with(['event.contact', 'account'])
                ->orderByDesc('id')
                ->paginate($this->perPage())
                ->through(fn (AdConversionDelivery $delivery) => AdConversionDeliveryResource::make($delivery)),
        );
    }

    public function summary(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingView->value);

        $validated = $this->filters($request);

        $rows = $this->filtered($validated)
            ->select('ad_platform_account_id')
            ->selectRaw('status')
            ->selectRaw('skip_reason')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('ad_platform_account_id', 'status', 'skip_reason')
            ->orderBy('ad_platform_account_id')
            ->get();

        return $this->success($rows->map(fn (AdConversionDelivery $row): array => [
            'account_id' => (int) $row->ad_platform_account_id,
            'status' => $row->status instanceof AdDeliveryStatus ? $row->status->value : (string) $row->status,
            'skip_reason' => $row->skip_reason instanceof AdDeliverySkipReason ? $row->skip_reason->value : $row->skip_reason,
            'count' => (int) $row->getAttribute('count'),
        ])->all());
    }

    public function retry(AdConversionDelivery $adConversionDelivery): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingManage->value);

        if ($adConversionDelivery->status !== AdDeliveryStatus::Failed) {
            throw ValidationException::withMessages([
                'status' => [__('errors.advertising.retry_only_failed')],
            ]);
        }

        $adConversionDelivery->status = AdDeliveryStatus::Pending;
        $adConversionDelivery->attempts = 0;
        $adConversionDelivery->error = null;
        $adConversionDelivery->skip_reason = null;
        $adConversionDelivery->claimed_at = null;
        $adConversionDelivery->processed_at = null;
        $adConversionDelivery->save();

        $adConversionDelivery->loadMissing('account');
        RecordsActivity::log(LogChannel::Ads, 'ads.delivery.retried', $adConversionDelivery->account, [
            'account_id' => $adConversionDelivery->ad_platform_account_id,
            'delivery_id' => $adConversionDelivery->id,
        ]);

        DeliverAdConversions::dispatch();

        return $this->success(AdConversionDeliveryResource::make(
            $adConversionDelivery->fresh(['event.contact', 'account']),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'account' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', Rule::enum(AdDeliveryStatus::class)],
            'skip_reason' => ['nullable', 'string', Rule::enum(AdDeliverySkipReason::class)],
            'event_key' => ['nullable', 'string', Rule::enum(ConversionEventKey::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'contact' => ['nullable', 'integer'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AdConversionDelivery>
     */
    private function filtered(array $filters): Builder
    {
        $query = AdConversionDelivery::query();

        if (! empty($filters['account'])) {
            $query->where('ad_platform_account_id', (int) $filters['account']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['skip_reason'])) {
            $query->where('skip_reason', $filters['skip_reason']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse((string) $filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', CarbonImmutable::parse((string) $filters['to'])->endOfDay());
        }

        if (! empty($filters['event_key']) || ! empty($filters['contact'])) {
            $query->whereHas('event', function (Builder $event) use ($filters): void {
                if (! empty($filters['event_key'])) {
                    $event->where('event_key', $filters['event_key']);
                }
                if (! empty($filters['contact'])) {
                    $event->where('contact_id', (int) $filters['contact']);
                }
            });
        }

        return $query;
    }
}
