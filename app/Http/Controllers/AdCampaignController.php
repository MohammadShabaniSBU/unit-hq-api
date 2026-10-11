<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LogChannel;
use App\Http\Resources\AdCampaignResource;
use App\Models\AdCampaign;
use App\Models\AdSpendDaily;
use App\Models\Employee;
use App\Models\Site;
use App\Models\UnitClassRate;
use App\Support\Advertising\AdCampaignStatus;
use App\Support\Advertising\AdPlatform;
use App\Support\Auth\Permission;
use App\Support\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdCampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingView->value);

        $employee = $this->employee($request);
        $validated = $request->validate([
            'ad_platform_account_id' => ['sometimes', 'integer'],
            'provider' => ['sometimes', 'string', Rule::enum(AdPlatform::class)],
            'status' => ['sometimes', 'string', Rule::enum(AdCampaignStatus::class)],
            'mapped' => ['sometimes', 'in:true,false,1,0'],
            'site_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        ]);

        $query = AdCampaign::query()
            ->visibleTo($employee, Permission::AdvertisingView)
            ->with('account');

        if (array_key_exists('ad_platform_account_id', $validated)) {
            $query->where('ad_platform_account_id', (int) $validated['ad_platform_account_id']);
        }

        if (array_key_exists('provider', $validated)) {
            $query->whereHas('account', function (Builder $account) use ($validated): void {
                $account->where('provider', $validated['provider']);
            });
        }

        if (array_key_exists('status', $validated)) {
            $query->where('status', $validated['status']);
        }

        if (array_key_exists('site_id', $validated)) {
            $query->where('site_id', (int) $validated['site_id']);
        }

        if (array_key_exists('mapped', $validated)) {
            $mapped = in_array((string) $validated['mapped'], ['1', 'true'], true);

            if ($mapped) {
                $query->whereNotNull('site_id');
            } else {
                $query->whereNull('site_id');
            }
        }

        $paginator = $query->orderBy('name')->orderBy('id')->paginate($this->perPage());
        /** @var Collection<int, AdCampaign> $campaigns */
        $campaigns = $paginator->getCollection();
        $suggested = $this->suggestions($campaigns);
        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;
        $spend = is_string($from) && is_string($to)
            ? $this->spendFor($campaigns, $from, $to)
            : null;

        return $this->paginated(
            $paginator->through(function (AdCampaign $campaign) use ($suggested, $spend): AdCampaignResource {
                return AdCampaignResource::forCampaign(
                    $campaign,
                    $suggested[$campaign->id] ?? null,
                    $spend === null ? null : ($spend[$campaign->id] ?? []),
                );
            }),
        );
    }

    public function updateMapping(Request $request, AdCampaign $adCampaign): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingManage->value);

        $employee = $this->employee($request);

        if ($this->visibleCampaign($employee, $adCampaign) === null) {
            return $this->notFound('Ad campaign not found.');
        }

        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'unit_class_id' => ['sometimes', 'nullable', 'integer', 'exists:unit_classes,id'],
            'utm_campaign_hint' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $siteId = (int) $validated['site_id'];
        $this->assertSite($employee, $siteId);

        $unitClassId = array_key_exists('unit_class_id', $validated)
            ? ($validated['unit_class_id'] === null ? null : (int) $validated['unit_class_id'])
            : $adCampaign->unit_class_id;
        $hint = array_key_exists('utm_campaign_hint', $validated)
            ? $this->hint($validated['utm_campaign_hint'])
            : $adCampaign->utm_campaign_hint;

        $this->assertUnitClass($unitClassId, $siteId);
        $this->writeMapping($adCampaign, $employee, $siteId, $unitClassId, $hint);

        $adCampaign->refresh()->load('account');

        return $this->success($this->payload($adCampaign));
    }

    public function bulkMapping(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingManage->value);

        $employee = $this->employee($request);
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
            'site_id' => ['required', 'integer', 'exists:sites,id'],
        ]);

        /** @var list<int> $ids */
        $ids = array_map(intval(...), $validated['ids']);
        $siteId = (int) $validated['site_id'];
        $this->assertSite($employee, $siteId);

        $campaigns = AdCampaign::query()
            ->visibleTo($employee, Permission::AdvertisingManage)
            ->whereIn('id', $ids)
            ->get();

        if ($campaigns->count() !== count($ids)) {
            return $this->notFound('Ad campaign not found.');
        }

        foreach ($campaigns as $campaign) {
            $this->assertUnitClass($campaign->unit_class_id, $siteId);
        }

        DB::transaction(function () use ($campaigns, $employee, $siteId): void {
            foreach ($campaigns as $campaign) {
                $this->writeMapping(
                    $campaign,
                    $employee,
                    $siteId,
                    $campaign->unit_class_id,
                    $campaign->utm_campaign_hint,
                );
            }
        });

        $campaigns->load('account');

        return $this->success($campaigns->map(fn (AdCampaign $campaign): array => $this->payload($campaign))->values()->all());
    }

    private function writeMapping(
        AdCampaign $campaign,
        Employee $employee,
        int $siteId,
        ?int $unitClassId,
        ?string $hint,
    ): void {
        $apply = function () use ($campaign, $employee, $siteId, $unitClassId, $hint): void {
            $campaign->site_id = $siteId;
            $campaign->unit_class_id = $unitClassId;
            $campaign->utm_campaign_hint = $hint;
            $campaign->mapped_by = $employee->id;
            $campaign->mapped_at = now();
            $campaign->save();

            RecordsActivity::log(LogChannel::Ads, 'ads.campaign.mapped', $campaign, [
                'account_id' => $campaign->ad_platform_account_id,
                'campaign_id' => $campaign->id,
                'site_id' => $siteId,
                'unit_class_id' => $unitClassId,
            ]);
        };

        if (DB::transactionLevel() > 0) {
            $apply();

            return;
        }

        DB::transaction($apply);
    }

    /** @return array<string, mixed> */
    private function payload(AdCampaign $campaign): array
    {
        $suggested = $this->suggestions([$campaign]);

        return AdCampaignResource::forCampaign(
            $campaign,
            $suggested[$campaign->id] ?? null,
            null,
        )->resolve();
    }

    private function visibleCampaign(Employee $employee, AdCampaign $campaign): ?AdCampaign
    {
        return AdCampaign::query()
            ->visibleTo($employee, Permission::AdvertisingManage)
            ->whereKey($campaign->id)
            ->first();
    }

    private function assertSite(Employee $employee, int $siteId): void
    {
        $site = Site::query()->find($siteId);

        if ($site === null || $site->archived_at !== null) {
            throw ValidationException::withMessages([
                'site_id' => [__('errors.advertising.site_archived')],
            ]);
        }

        $granted = $employee->siteIdsFor(Permission::AdvertisingManage);

        if (is_array($granted) && ! in_array($siteId, array_map(intval(...), $granted), true)) {
            throw ValidationException::withMessages([
                'site_id' => [__('errors.advertising.site_not_granted')],
            ]);
        }
    }

    private function assertUnitClass(?int $unitClassId, int $siteId): void
    {
        if ($unitClassId === null) {
            return;
        }

        $belongs = UnitClassRate::query()
            ->where('unit_class_id', $unitClassId)
            ->where('site_id', $siteId)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'unit_class_id' => [__('errors.advertising.unit_class_not_at_site')],
            ]);
        }
    }

    /**
     * @param  iterable<AdCampaign>  $campaigns
     * @return array<int, int|null>
     */
    private function suggestions(iterable $campaigns): array
    {
        $folded = [];

        foreach (Site::query()->active()->get(['id', 'name', 'code']) as $site) {
            $name = $this->fold($site->name);
            $code = $this->fold((string) $site->code);

            if ($name === '' && $code === '') {
                continue;
            }

            $folded[] = [
                'id' => $site->id,
                'name' => $name,
                'code' => $code,
            ];
        }

        $out = [];

        foreach ($campaigns as $campaign) {
            $haystack = $this->fold($campaign->name);
            $matches = [];

            if ($haystack !== '') {
                foreach ($folded as $site) {
                    $hit = ($site['name'] !== '' && str_contains($haystack, $site['name']))
                        || ($site['code'] !== '' && str_contains($haystack, $site['code']));

                    if ($hit) {
                        $matches[$site['id']] = $site['id'];
                    }
                }
            }

            $out[$campaign->id] = count($matches) === 1 ? (int) array_key_first($matches) : null;
        }

        return $out;
    }

    /**
     * @param  iterable<AdCampaign>  $campaigns
     * @return array<int, list<array{currency: string, cost: string, impressions: int, clicks: int}>>
     */
    private function spendFor(iterable $campaigns, string $from, string $to): array
    {
        $grouped = [];

        foreach ($campaigns as $campaign) {
            $grouped[$campaign->id] = [];
        }

        if ($grouped === []) {
            return [];
        }

        $rows = AdSpendDaily::query()
            ->whereIn('ad_campaign_id', array_keys($grouped))
            ->whereDate('spend_date', '>=', $from)
            ->whereDate('spend_date', '<=', $to)
            ->get(['ad_campaign_id', 'currency', 'cost', 'impressions', 'clicks']);

        foreach ($rows as $row) {
            $id = (int) $row->ad_campaign_id;
            $currency = strtoupper((string) $row->currency);
            $bucket = $grouped[$id][$currency] ?? [
                'cost' => '0.00',
                'impressions' => 0,
                'clicks' => 0,
            ];
            $bucket['cost'] = bcadd($bucket['cost'], (string) $row->cost, 2);
            $bucket['impressions'] += (int) $row->impressions;
            $bucket['clicks'] += (int) $row->clicks;
            $grouped[$id][$currency] = $bucket;
        }

        $out = [];

        foreach ($grouped as $id => $byCurrency) {
            ksort($byCurrency);
            $list = [];

            foreach ($byCurrency as $currency => $bucket) {
                $list[] = [
                    'currency' => $currency,
                    'cost' => $bucket['cost'],
                    'impressions' => $bucket['impressions'],
                    'clicks' => $bucket['clicks'],
                ];
            }

            $out[$id] = $list;
        }

        return $out;
    }

    private function hint(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function fold(string $value): string
    {
        return mb_strtolower(Str::ascii(trim($value)));
    }

    private function employee(Request $request): Employee
    {
        $user = $request->user();

        if (! $user instanceof Employee) {
            abort(403);
        }

        return $user;
    }
}
