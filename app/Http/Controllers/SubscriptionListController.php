<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Employee;
use App\Models\SubscriptionList;
use App\Support\Auth\Permission;
use App\Support\Communications\Channel;
use App\Support\Marketing\ConsentState;
use App\Support\Marketing\SubscriptionListImport;
use App\Support\Marketing\SubscriptionListKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SubscriptionListController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::MarketingView->value);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
        ]);

        $query = SubscriptionList::query()
            ->orderByDesc('is_system')
            ->orderBy('key');

        match ($validated['status'] ?? 'active') {
            'archived' => $query->whereNotNull('archived_at'),
            'all' => null,
            default => $query->active(),
        };

        $lists = $query->get()->map(fn (SubscriptionList $list): array => $this->payload($list))->all();

        return $this->success($lists, 'Subscription lists retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::MarketingManage->value);

        $validated = $this->validatePayload($request, creating: true);

        $list = SubscriptionList::query()->create([
            ...$validated,
            'is_system' => false,
        ]);

        return $this->created($this->payload($list), 'Subscription list created successfully.');
    }

    public function update(Request $request, SubscriptionList $subscriptionList): JsonResponse
    {
        Gate::authorize(Permission::MarketingManage->value);

        if ($request->exists('key') && (string) $request->input('key') !== $subscriptionList->key) {
            return $this->error(__('errors.subscription_lists.key_immutable'), statusCode: 422);
        }

        if ($subscriptionList->is_system && $this->lockedFieldsChange($request, $subscriptionList)) {
            return $this->error(__('errors.subscription_lists.system_fields'), statusCode: 422);
        }

        $validated = $this->validatePayload($request, creating: false);
        $attributes = [];
        foreach (['labels', 'descriptions', 'kind', 'channels', 'site_id', 'is_public', 'requires_double_opt_in'] as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = $validated[$field];
            }
        }

        if ($attributes !== []) {
            $subscriptionList->update($attributes);
            $subscriptionList->forgetMemberCounts();
        }

        return $this->success(
            $this->payload($subscriptionList->fresh() ?? $subscriptionList),
            'Subscription list updated successfully.',
        );
    }

    public function archive(SubscriptionList $subscriptionList): JsonResponse
    {
        Gate::authorize(Permission::MarketingManage->value);

        if ($subscriptionList->is_system) {
            return $this->error(__('errors.subscription_lists.system_archive'), statusCode: 422);
        }

        if (! $subscriptionList->isArchived()) {
            $subscriptionList->update(['archived_at' => now()]);
            $subscriptionList->forgetMemberCounts();
        }

        return $this->success(
            $this->payload($subscriptionList->fresh() ?? $subscriptionList),
            'Subscription list archived successfully.',
        );
    }

    public function members(Request $request, SubscriptionList $subscriptionList): JsonResponse
    {
        Gate::authorize(Permission::MarketingView->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'channel' => ['nullable', 'string', Rule::in([
                Channel::Email->value,
                Channel::Sms->value,
                Channel::Whatsapp->value,
            ])],
        ]);

        $channels = $subscriptionList->marketingChannels();
        if (isset($validated['channel'])) {
            $channel = Channel::from($validated['channel']);
            if (! $subscriptionList->allows($channel)) {
                return $this->error(__('errors.consent.list_channel'), statusCode: 422);
            }
            $channels = [$channel];
        }

        $query = Contact::query()
            ->visibleTo($employee, Permission::MarketingView)
            ->orderBy('id');

        if ($channels === []) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where(function ($inner) use ($subscriptionList, $channels): void {
                $inner->whereRaw('1 = 0');
                foreach ($channels as $channel) {
                    $inner->orWhereIn(
                        'contacts.id',
                        ConsentState::grantedQuery($channel, $subscriptionList->id),
                    );
                }
            });
        }

        $paginator = $query->paginate($this->perPage());
        $ids = collect($paginator->items())->map(static fn (Contact $contact): int => $contact->id)->all();
        $granted = $this->channelsByContact($subscriptionList, $channels, $ids);

        $paginator->setCollection($paginator->getCollection()->map(function (Contact $contact) use ($granted): array {
            return [
                'id' => $contact->id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'email' => $contact->email,
                'channels' => $granted[$contact->id] ?? [],
            ];
        }));

        return $this->paginated($paginator, 'Subscription list members retrieved successfully.');
    }

    public function import(Request $request, SubscriptionList $subscriptionList): JsonResponse
    {
        Gate::authorize(Permission::MarketingManage->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'confirm' => ['sometimes', 'boolean'],
            'create_missing' => ['sometimes', 'boolean'],
            'consent_text_id' => ['required_if:confirm,1,true', 'nullable', 'integer', 'exists:consent_texts,id'],
        ]);

        $confirm = $request->boolean('confirm');
        if ($confirm && ! isset($validated['consent_text_id'])) {
            return $this->error(__('errors.consent.consent_text'), statusCode: 422);
        }

        $contents = (string) $request->file('file')?->getContent();
        $result = SubscriptionListImport::run(
            $subscriptionList,
            $employee,
            $contents,
            $confirm,
            $request->boolean('create_missing'),
            isset($validated['consent_text_id']) ? (int) $validated['consent_text_id'] : null,
        );

        if ($result->failed()) {
            $key = $result->errorKey === 'list_archived'
                ? 'errors.consent.list_archived'
                : 'errors.subscription_lists.'.$result->errorKey;

            return $this->error(__($key), statusCode: 422);
        }

        return $this->success($result->report(), 'Subscription list import finished.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $creating): array
    {
        $presence = $creating ? 'required' : 'sometimes';
        $labelRule = $creating ? 'required' : 'required_with:labels';

        return $request->validate([
            'key' => $creating
                ? ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:subscription_lists,key']
                : ['sometimes', 'string'],
            'labels' => [$presence, 'array'],
            'labels.en' => [$labelRule, 'string', 'max:255'],
            'labels.es' => [$labelRule, 'string', 'max:255'],
            'labels.fr' => [$labelRule, 'string', 'max:255'],
            'descriptions' => ['nullable', 'array'],
            'descriptions.en' => ['nullable', 'string', 'max:2000'],
            'descriptions.es' => ['nullable', 'string', 'max:2000'],
            'descriptions.fr' => ['nullable', 'string', 'max:2000'],
            'kind' => [$presence, 'string', Rule::enum(SubscriptionListKind::class)],
            'channels' => [$presence, 'array', 'min:1'],
            'channels.*' => ['string', Rule::in([
                Channel::Email->value,
                Channel::Sms->value,
                Channel::Whatsapp->value,
            ])],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'is_public' => ['sometimes', 'boolean'],
            'requires_double_opt_in' => ['sometimes', 'boolean'],
        ]);
    }

    private function lockedFieldsChange(Request $request, SubscriptionList $list): bool
    {
        if ($request->exists('kind') && (string) $request->input('kind') !== $list->kind->value) {
            return true;
        }

        if ($request->exists('site_id')) {
            $incoming = $request->input('site_id');
            $incomingId = $incoming === null || $incoming === '' ? null : (int) $incoming;
            if ($incomingId !== $list->site_id) {
                return true;
            }
        }

        if ($request->exists('channels') && is_array($request->input('channels'))) {
            $incoming = array_values($request->input('channels'));
            $current = array_values($list->channels);
            sort($incoming);
            sort($current);
            if ($incoming !== $current) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SubscriptionList $list): array
    {
        return [
            'id' => $list->id,
            'key' => $list->key,
            'labels' => $list->labels,
            'descriptions' => $list->descriptions,
            'kind' => $list->kind->value,
            'channels' => $list->channels,
            'site_id' => $list->site_id,
            'is_public' => $list->is_public,
            'requires_double_opt_in' => $list->requires_double_opt_in,
            'is_system' => $list->is_system,
            'archived_at' => $list->archived_at?->toIso8601String(),
            'member_counts' => $this->memberCounts($list),
            'created_at' => $list->created_at->toIso8601String(),
            'updated_at' => $list->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function memberCounts(SubscriptionList $list): array
    {
        return Cache::remember(
            SubscriptionList::memberCountCacheKey($list->id),
            60,
            function () use ($list): array {
                $counts = [];
                foreach ($list->consentChannels() as $channel) {
                    $counts[$channel->value] = (int) ConsentState::grantedQuery($channel, $list->id)->count();
                }

                return $counts;
            },
        );
    }

    /**
     * @param  list<Channel>  $channels
     * @param  list<int>  $contactIds
     * @return array<int, list<string>>
     */
    private function channelsByContact(SubscriptionList $list, array $channels, array $contactIds): array
    {
        $granted = [];
        if ($contactIds === []) {
            return $granted;
        }

        foreach ($channels as $channel) {
            $ids = ConsentState::grantedQuery($channel, $list->id)
                ->whereIn('contact_id', $contactIds)
                ->pluck('contact_id');

            foreach ($ids as $id) {
                $granted[(int) $id][] = $channel->value;
            }
        }

        return $granted;
    }
}
