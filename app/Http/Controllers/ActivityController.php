<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LogChannel;
use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Offer;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActivityController extends Controller
{
    /** @var array<string, class-string<Model>> */
    private const SUBJECT_MAP = [
        'contact' => Contact::class,
        'deal' => Deal::class,
        'offer' => Offer::class,
        'reservation' => Reservation::class,
        'contract' => Contract::class,
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject_type' => ['nullable', 'string', Rule::in(array_keys(self::SUBJECT_MAP))],
            'subject_id' => ['nullable', 'integer', 'required_with:subject_type'],
            'log_name' => ['nullable', 'array'],
            'log_name.*' => ['string', Rule::in(array_column(LogChannel::cases(), 'value'))],
            'causer_type' => ['nullable', 'string'],
            'causer_id' => ['nullable', 'integer', 'required_with:causer_type'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'include_disabled' => ['nullable', 'boolean'],
        ]);

        $settings = Setting::activityLog();
        $includeDisabled = (bool) ($validated['include_disabled'] ?? false);
        $user = $request->user();
        $canIncludeDisabled = $includeDisabled && $user instanceof User;

        $query = Activity::query()->with('causer')->latest('id');

        if (! empty($validated['subject_type']) && ! empty($validated['subject_id'])) {
            $query->where('subject_type', self::SUBJECT_MAP[$validated['subject_type']])
                ->where('subject_id', $validated['subject_id']);
        }

        if (! empty($validated['log_name'])) {
            $query->whereIn('log_name', $validated['log_name']);
        }

        if (! empty($validated['causer_type']) && ! empty($validated['causer_id'])) {
            $query->where('causer_type', $validated['causer_type'])
                ->where('causer_id', $validated['causer_id']);
        }

        if (! empty($validated['from'])) {
            $query->where('created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->where('created_at', '<=', $validated['to']);
        }

        if (! $canIncludeDisabled) {
            $enabled = array_merge(
                [LogChannel::Core->value],
                $settings->channels,
            );
            $query->whereIn('log_name', $enabled);
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(
                fn (Activity $activity) => ActivityResource::make($activity)
            ),
            'Activities retrieved successfully.'
        );
    }
}
