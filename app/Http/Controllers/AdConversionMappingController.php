<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LogChannel;
use App\Models\AdConversionMapping;
use App\Models\AdPlatformAccount;
use App\Support\Advertising\AdPlatform;
use App\Support\Advertising\AdPlatformException;
use App\Support\Advertising\AdPlatformRegistry;
use App\Support\Advertising\Contracts\ListsConversionTargets;
use App\Support\Advertising\Contracts\ReportsConversions;
use App\Support\Advertising\ConversionEventKey;
use App\Support\Advertising\ConversionValueRule;
use App\Support\Advertising\Hashing;
use App\Support\Advertising\Results\ConversionPayload;
use App\Support\Auth\Permission;
use App\Support\RecordsActivity;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdConversionMappingController extends Controller
{
    private const META_EVENT_NAME = '/^[A-Za-z0-9_]{1,40}$/';

    public function show(AdPlatformAccount $adPlatformAccount): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingView->value);

        return $this->success([
            'mappings' => $this->rows($adPlatformAccount),
        ]);
    }

    public function update(Request $request, AdPlatformAccount $adPlatformAccount): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingManage->value);
        $this->assertOpen($adPlatformAccount);

        $validated = $request->validate([
            'mappings' => ['required', 'array', 'size:3'],
            'mappings.*.event_key' => ['required', 'string', Rule::enum(ConversionEventKey::class), 'distinct'],
            'mappings.*.external_ref' => ['required', 'string', 'max:255'],
            'mappings.*.value_rule' => ['required', 'string', Rule::enum(ConversionValueRule::class)],
            'mappings.*.is_active' => ['required', 'boolean'],
        ]);

        /** @var list<array{event_key: string, external_ref: string, value_rule: string, is_active: bool}> $mappings */
        $mappings = $validated['mappings'];
        $this->assertComplete($adPlatformAccount, $mappings);

        DB::transaction(function () use ($adPlatformAccount, $mappings): void {
            foreach ($mappings as $row) {
                $eventKey = ConversionEventKey::from($row['event_key']);
                $valueRule = ConversionValueRule::from($row['value_rule']);
                $existing = AdConversionMapping::query()
                    ->where('ad_platform_account_id', $adPlatformAccount->id)
                    ->where('event_key', $eventKey)
                    ->first();

                $changed = $existing === null
                    || $existing->external_ref !== $row['external_ref']
                    || $existing->value_rule !== $valueRule
                    || $existing->is_active !== $row['is_active'];

                AdConversionMapping::query()->updateOrCreate(
                    [
                        'ad_platform_account_id' => $adPlatformAccount->id,
                        'event_key' => $eventKey,
                    ],
                    [
                        'external_ref' => $row['external_ref'],
                        'value_rule' => $valueRule,
                        'is_active' => $row['is_active'],
                    ],
                );

                if (! $changed) {
                    continue;
                }

                RecordsActivity::log(LogChannel::Ads, 'ads.conversion_mapping.updated', $adPlatformAccount, [
                    'account_id' => $adPlatformAccount->id,
                    'event_key' => $eventKey->value,
                    'old_external_ref' => $existing?->external_ref,
                    'new_external_ref' => $row['external_ref'],
                    'old_value_rule' => $existing?->value_rule->value,
                    'new_value_rule' => $valueRule->value,
                ]);
            }
        });

        $adPlatformAccount->unsetRelation('conversionMappings');

        return $this->success([
            'mappings' => $this->rows($adPlatformAccount),
        ]);
    }

    public function targets(AdPlatformAccount $adPlatformAccount): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingManage->value);
        $this->assertOpen($adPlatformAccount);

        try {
            $adapter = app(AdPlatformRegistry::class)->forAccount($adPlatformAccount);
        } catch (AdPlatformException $exception) {
            throw ValidationException::withMessages([
                'account' => [$exception->getMessage()],
            ]);
        }

        if (! $adapter instanceof ListsConversionTargets) {
            throw ValidationException::withMessages([
                'account' => [__('errors.advertising.not_conversion_capable')],
            ]);
        }

        try {
            $targets = $adapter->conversionTargets();
        } catch (AdPlatformException $exception) {
            throw ValidationException::withMessages([
                'account' => [$exception->getMessage()],
            ]);
        }

        return $this->success($targets);
    }

    public function test(Request $request, AdPlatformAccount $adPlatformAccount): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingManage->value);
        $this->assertOpen($adPlatformAccount);

        $validated = $request->validate([
            'event_key' => ['required', 'string', Rule::enum(ConversionEventKey::class)],
            'test_event_code' => ['nullable', 'string', 'max:64'],
        ]);

        $eventKey = ConversionEventKey::from($validated['event_key']);
        $testEventCode = isset($validated['test_event_code']) ? (string) $validated['test_event_code'] : null;

        if ($adPlatformAccount->provider === AdPlatform::Meta && ($testEventCode === null || $testEventCode === '')) {
            throw ValidationException::withMessages([
                'test_event_code' => [__('errors.advertising.test_event_code_required')],
            ]);
        }

        $mapping = AdConversionMapping::query()
            ->where('ad_platform_account_id', $adPlatformAccount->id)
            ->where('event_key', $eventKey)
            ->first();

        if ($mapping === null) {
            throw ValidationException::withMessages([
                'event_key' => [__('errors.advertising.mapping_missing')],
            ]);
        }

        try {
            $adapter = app(AdPlatformRegistry::class)->forAccount($adPlatformAccount);
        } catch (AdPlatformException $exception) {
            throw ValidationException::withMessages([
                'account' => [$exception->getMessage()],
            ]);
        }

        if (! $adapter instanceof ReportsConversions) {
            throw ValidationException::withMessages([
                'account' => [__('errors.advertising.not_conversion_capable')],
            ]);
        }

        $payload = new ConversionPayload(
            dedupeId: 'test:'.$adPlatformAccount->id.':'.$eventKey->value.':'.Str::uuid(),
            externalRef: $mapping->external_ref,
            gclid: $adPlatformAccount->provider === AdPlatform::GoogleAds ? 'test-gclid' : null,
            conversionDateTime: CarbonImmutable::now()->utc()->format('Y-m-d H:i:sP'),
            eventTime: CarbonImmutable::now()->getTimestamp(),
            metaUserData: $adPlatformAccount->provider === AdPlatform::Meta ? [
                'em' => [Hashing::sha256(Hashing::normaliseEmail('test@example.com'))],
                'external_id' => [Hashing::sha256('contact:0')],
            ] : [],
        );

        try {
            $results = $adapter->deliver(
                [$payload],
                $adPlatformAccount->provider === AdPlatform::GoogleAds,
                $testEventCode,
            );
        } catch (AdPlatformException $exception) {
            throw ValidationException::withMessages([
                'account' => [$exception->getMessage()],
            ]);
        }

        $result = $results[0] ?? null;

        return $this->success([
            'ok' => $result?->ok ?? false,
            'error' => $result?->error,
            'request_id' => $result?->providerRequestId,
        ]);
    }

    private function assertOpen(AdPlatformAccount $account): void
    {
        if ($account->archived_at !== null) {
            throw ValidationException::withMessages([
                'account' => [__('errors.advertising.account_archived')],
            ]);
        }
    }

    /**
     * @param  list<array{event_key: string, external_ref: string, value_rule: string, is_active: bool}>  $mappings
     */
    private function assertComplete(AdPlatformAccount $account, array $mappings): void
    {
        $keys = array_map(static fn (array $row): string => $row['event_key'], $mappings);
        sort($keys);
        $expected = array_map(static fn (ConversionEventKey $key): string => $key->value, ConversionEventKey::cases());
        sort($expected);

        if ($keys !== $expected) {
            throw ValidationException::withMessages([
                'mappings' => [__('errors.advertising.mappings_required')],
            ]);
        }

        foreach ($mappings as $index => $row) {
            if ($row['value_rule'] !== ConversionValueRule::None->value && $row['event_key'] !== ConversionEventKey::MoveIn->value) {
                throw ValidationException::withMessages([
                    'mappings.'.$index.'.value_rule' => [__('errors.advertising.value_rule_move_in_only')],
                ]);
            }

            if ($account->provider === AdPlatform::Meta && preg_match(self::META_EVENT_NAME, $row['external_ref']) !== 1) {
                throw ValidationException::withMessages([
                    'mappings.'.$index.'.external_ref' => [__('errors.advertising.meta_event_name')],
                ]);
            }
        }
    }

    /**
     * @return list<array{event_key: string, external_ref: ?string, value_rule: string, is_active: bool}>
     */
    private function rows(AdPlatformAccount $account): array
    {
        $stored = $account->conversionMappings()->get()->keyBy(
            fn (AdConversionMapping $mapping): string => $mapping->event_key->value,
        );

        $rows = [];
        foreach (ConversionEventKey::cases() as $key) {
            $mapping = $stored->get($key->value);
            $rows[] = [
                'event_key' => $key->value,
                'external_ref' => $mapping?->external_ref,
                'value_rule' => $mapping?->value_rule->value ?? ConversionValueRule::None->value,
                'is_active' => $mapping?->is_active ?? false,
            ];
        }

        return $rows;
    }
}
