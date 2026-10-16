<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Insurance;
use App\Models\InsuranceRate;
use App\Models\Price;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rename the demo insurance plans and open a new catalogue price window.
 * Existing price amounts stay immutable.
 */
class DemoRenameInsurancePlansCommand extends Command
{
    protected $signature = 'demo:rename-insurance-plans {--dry-run : Preview remaps without writing}';

    protected $description = 'Rename demo insurance plans to Simple and VIP, and set coverage and catalogue price';

    /**
     * @var array<string, array{name: string, coverage: string, amount: string}>
     */
    private const PLANS = [
        'Básico' => ['name' => 'Simple', 'coverage' => '5000.00', 'amount' => '5.00'],
        'Premium' => ['name' => 'VIP', 'coverage' => '10000.00', 'amount' => '10.00'],
    ];

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('demo:rename-insurance-plans refuses to run in the production environment.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Dry run — no writes.');
        }

        $plan = $this->plan();
        if ($plan['collision'] !== null) {
            $this->error($plan['collision']);

            return self::FAILURE;
        }

        if (! $dryRun && ($plan['plans'] !== [] || $plan['prices'] !== [])) {
            DB::transaction(function () use ($plan): void {
                foreach ($plan['plans'] as $change) {
                    Insurance::query()->whereKey($change['id'])->update([
                        'name' => $change['name'],
                        'coverage' => $change['coverage'],
                    ]);
                }

                $today = Carbon::today()->toDateString();
                foreach ($plan['prices'] as $change) {
                    $current = Price::query()->findOrFail($change['id']);
                    $current->update(['effective_to' => $today]);

                    Price::query()->create([
                        'priceable_type' => 'insurance_rate',
                        'priceable_id' => $change['rate_id'],
                        'scope' => Price::SCOPE_CATALOGUE,
                        'amount' => $change['amount'],
                        'currency' => $change['currency'],
                        'effective_from' => $today,
                        'effective_to' => null,
                        'created_by' => $change['created_by'],
                    ]);
                }
            });
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Plans updated', (string) count($plan['plans'])],
                ['Prices replaced', (string) count($plan['prices'])],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     collision: string|null,
     *     plans: list<array{id: int, name: string, coverage: string}>,
     *     prices: list<array{id: int, rate_id: int, amount: string, currency: string, created_by: int}>
     * }
     */
    private function plan(): array
    {
        $plans = [];
        $prices = [];

        foreach (self::PLANS as $from => $target) {
            $insurance = Insurance::query()->where('name', $from)->first();
            if ($insurance === null) {
                continue;
            }

            $taken = Insurance::query()
                ->where('name', $target['name'])
                ->whereKeyNot($insurance->id)
                ->exists();
            if ($taken) {
                return [
                    'collision' => "Target name {$target['name']} already exists.",
                    'plans' => [],
                    'prices' => [],
                ];
            }

            if ($insurance->name !== $target['name'] || ! $this->sameMoney($insurance->coverage, $target['coverage'])) {
                $plans[] = [
                    'id' => $insurance->id,
                    'name' => $target['name'],
                    'coverage' => $target['coverage'],
                ];
            }

            $rates = InsuranceRate::query()
                ->where('insurance_id', $insurance->id)
                ->with('price')
                ->orderBy('id')
                ->get();

            foreach ($rates as $rate) {
                $current = $rate->price;
                if ($current === null || $this->sameMoney($current->amount, $target['amount'])) {
                    continue;
                }

                $prices[] = [
                    'id' => $current->id,
                    'rate_id' => $rate->id,
                    'amount' => $target['amount'],
                    'currency' => (string) $current->currency,
                    'created_by' => (int) $current->created_by,
                ];
            }
        }

        return [
            'collision' => null,
            'plans' => $plans,
            'prices' => $prices,
        ];
    }

    private function sameMoney(mixed $left, string $right): bool
    {
        return number_format((float) $left, 2, '.', '') === number_format((float) $right, 2, '.', '');
    }
}
