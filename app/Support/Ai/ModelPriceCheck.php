<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AiAgent;
use App\Models\AiModelPrice;
use App\Models\AiProviderAccount;
use App\Models\AiUsageEvent;
use Illuminate\Support\Carbon;

final class ModelPriceCheck
{
    /**
     * @return list<array{provider: string|null, model: string, source: string}>
     */
    public static function missing(?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();
        $date = $asOf->toDateString();
        $since = $asOf->copy()->subDays(30)->startOfDay();
        $defaultProvider = (string) config('ai.default');

        $seen = [];
        $missing = [];

        $usagePairs = AiUsageEvent::query()
            ->where('started_at', '>=', $since)
            ->whereNotNull('settled_at')
            ->whereNotNull('model')
            ->select('provider', 'model')
            ->distinct()
            ->get();

        foreach ($usagePairs as $row) {
            $provider = $row->provider;
            $model = (string) $row->model;
            $key = ($provider ?? '')."\0".$model;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if ($provider === null || $provider === '' || ! self::hasPrice($provider, $model, $date)) {
                $missing[] = [
                    'provider' => $provider,
                    'model' => $model,
                    'source' => 'usage',
                ];
            }
        }

        $configured = [];

        $defaultModel = (string) config('agents.default_model');
        if ($defaultModel !== '') {
            $configured[] = [$defaultProvider, $defaultModel, 'config'];
        }

        foreach (AiAgent::query()->active()->pluck('model') as $model) {
            $model = (string) $model;
            if ($model !== '') {
                $configured[] = [$defaultProvider, $model, 'ai_agents'];
            }
        }

        foreach (AiProviderAccount::query()->active()->whereNotNull('default_model')->get() as $account) {
            $model = (string) $account->default_model;
            if ($model !== '') {
                $provider = $account->provider instanceof \BackedEnum
                    ? $account->provider->value
                    : (string) $account->provider;
                $configured[] = [$provider, $model, 'ai_provider_accounts'];
            }
        }

        foreach ($configured as [$provider, $model, $source]) {
            $key = $provider."\0".$model;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if (! self::hasPrice($provider, $model, $date)) {
                $missing[] = [
                    'provider' => $provider,
                    'model' => $model,
                    'source' => $source,
                ];
            }
        }

        return $missing;
    }

    private static function hasPrice(string $provider, string $model, string $date): bool
    {
        return AiModelPrice::query()
            ->activeFor($provider, $model, $date)
            ->exists();
    }
}
