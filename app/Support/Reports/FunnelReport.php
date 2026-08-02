<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\PlaybookKind;
use App\Models\AutomationRun;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\Playbook;
use App\Support\Billing\BillingMath;
use App\Support\Playbooks\PlaybookEnrolmentSummary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Pipeline funnel for a deal-created cohort + lead-chase correlation + offer bands.
 * Definitions: docs/report-definitions.md — Funnel section.
 */
final class FunnelReport extends AbstractReport
{
    public static function name(): string
    {
        return 'funnel';
    }

    public function maxQueries(): int
    {
        return 40;
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $to = $filters->to ?? OccupancyMetrics::resolveAsOf($filters);
        $from = $filters->from ?? CarbonImmutable::parse($to)->subDays(90)->toDateString();
        $fromDt = CarbonImmutable::parse($from)->startOfDay();
        $toDt = CarbonImmutable::parse($to)->endOfDay();

        /** @var Collection<int, Deal> $deals */
        $deals = Deal::query()
            ->with('contact:id,source')
            ->whereBetween('created_at', [$fromDt->toDateTimeString(), $toDt->toDateTimeString()])
            ->when($filters->siteIds !== null, static fn (Builder $q) => $q->whereIn('site_id', $filters->siteIds))
            ->orderBy('id')
            ->get();

        $dealIds = $deals->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        /** @var Collection<int, Offer> $offers */
        $offers = $dealIds === []
            ? collect()
            : Offer::query()
                ->with(['options.unitClassRate.price'])
                ->whereIn('deal_id', $dealIds)
                ->get();

        $offersByDeal = $offers->groupBy(static fn (Offer $o): int => (int) $o->deal_id);

        /** @var Collection<int, Contract> $contracts */
        $contracts = $dealIds === []
            ? collect()
            : Contract::query()
                ->with('esignEnvelopes:id,contract_id')
                ->whereIn('deal_id', $dealIds)
                ->whereNotNull('signed_at')
                ->get();

        $contractsByDeal = $contracts->groupBy(static fn (Contract $c): int => (int) $c->deal_id);

        $leadChasePlaybook = Playbook::query()
            ->where('kind', PlaybookKind::LeadChase)
            ->whereNull('archived_at')
            ->orderByDesc('id')
            ->first();

        $enrolledDealIds = [];
        /** @var Collection<int, AutomationRun> $lineageRuns */
        $lineageRuns = collect();
        if ($leadChasePlaybook !== null && $dealIds !== []) {
            $lineageRuns = PlaybookEnrolmentSummary::lineageQuery((int) $leadChasePlaybook->id)
                ->where('subject_type', 'deal')
                ->whereIn('subject_id', $dealIds)
                ->with(['steps', 'automation.nodes'])
                ->get();
            foreach ($lineageRuns as $run) {
                if ($run->subject_id !== null) {
                    $enrolledDealIds[(int) $run->subject_id] = true;
                }
            }
        }

        /** @var list<float> $dealToSent */
        $dealToSent = [];
        /** @var list<float> $sentToViewed */
        $sentToViewed = [];
        /** @var list<float> $viewedToAccepted */
        $viewedToAccepted = [];
        /** @var list<float> $acceptedToSigned */
        $acceptedToSigned = [];

        $dealsSent = 0;
        $dealsViewed = 0;
        $dealsAccepted = 0;
        $dealsSigned = 0;
        $signedWalkIn = 0;
        $signedRemote = 0;
        $enrolledConverters = 0;
        $notEnrolledConverters = 0;
        $enrolledDeals = 0;
        $notEnrolledDeals = 0;
        /** @var array<string, int> $exitsByCause */
        $exitsByCause = [];
        /** @var array<int, int> $stepsReceivedConverters */
        $stepsReceivedConverters = [];

        foreach ($deals as $deal) {
            $dealId = (int) $deal->id;
            $enrolled = isset($enrolledDealIds[$dealId]);
            if ($enrolled) {
                $enrolledDeals++;
            } else {
                $notEnrolledDeals++;
            }

            /** @var Collection<int, Offer> $dealOffers */
            $dealOffers = $offersByDeal->get($dealId, collect());
            $bestSent = null;
            $bestViewed = null;
            $bestAccepted = null;

            foreach ($dealOffers as $offer) {
                if ($offer->sent_at !== null) {
                    $sentAt = CarbonImmutable::parse($offer->sent_at);
                    if ($bestSent === null || $sentAt->lt($bestSent)) {
                        $bestSent = $sentAt;
                    }
                }
                if ($offer->first_viewed_at !== null) {
                    $viewedAt = CarbonImmutable::parse($offer->first_viewed_at);
                    if ($bestViewed === null || $viewedAt->lt($bestViewed)) {
                        $bestViewed = $viewedAt;
                    }
                }
                if ($offer->accepted_at !== null) {
                    $acceptedAt = CarbonImmutable::parse($offer->accepted_at);
                    if ($bestAccepted === null || $acceptedAt->lt($bestAccepted)) {
                        $bestAccepted = $acceptedAt;
                    }
                }
            }

            if ($bestSent !== null) {
                $dealsSent++;
            }
            if ($bestViewed !== null) {
                $dealsViewed++;
            }
            if ($bestAccepted !== null) {
                $dealsAccepted++;
            }

            $dealCreated = CarbonImmutable::parse($deal->created_at);
            if ($bestSent !== null) {
                $dealToSent[] = abs($dealCreated->floatDiffInDays($bestSent));
            }
            if ($bestSent !== null && $bestViewed !== null) {
                $sentToViewed[] = abs($bestSent->floatDiffInDays($bestViewed));
            }
            if ($bestViewed !== null && $bestAccepted !== null) {
                $viewedToAccepted[] = abs($bestViewed->floatDiffInDays($bestAccepted));
            }

            /** @var Collection<int, Contract> $dealContracts */
            $dealContracts = $contractsByDeal->get($dealId, collect());
            if ($dealContracts->isEmpty()) {
                continue;
            }

            $dealsSigned++;
            if ($enrolled) {
                $enrolledConverters++;
            } else {
                $notEnrolledConverters++;
            }

            $earliestSigned = null;
            foreach ($dealContracts as $contract) {
                if ($contract->esignEnvelopes->isNotEmpty()) {
                    $signedRemote++;
                } else {
                    $signedWalkIn++;
                }
                if ($contract->signed_at !== null) {
                    $signedAt = CarbonImmutable::parse($contract->signed_at);
                    if ($earliestSigned === null || $signedAt->lt($earliestSigned)) {
                        $earliestSigned = $signedAt;
                    }
                }
            }

            if ($bestAccepted !== null && $earliestSigned !== null) {
                $acceptedToSigned[] = abs($bestAccepted->floatDiffInDays($earliestSigned));
            }

            if ($enrolled) {
                $dealRuns = $lineageRuns->where('subject_id', $dealId);
                $maxSteps = 0;
                foreach ($dealRuns as $run) {
                    $progress = PlaybookEnrolmentSummary::progress($run, $run->automation);
                    $maxSteps = max($maxSteps, $progress['steps_completed']);
                }
                $stepsReceivedConverters[$maxSteps] = ($stepsReceivedConverters[$maxSteps] ?? 0) + 1;
            }
        }

        foreach ($lineageRuns as $run) {
            if ($run->cancel_cause !== null) {
                $cause = $run->cancel_cause instanceof \BackedEnum
                    ? $run->cancel_cause->value
                    : (string) $run->cancel_cause;
                $exitsByCause[$cause] = ($exitsByCause[$cause] ?? 0) + 1;
            }
        }
        ksort($exitsByCause);

        $offerCount = $offers->count();
        $optionTotal = 0;
        $acceptedOffers = 0;
        /** @var array<string, array{offers: int, accepted: int}> $bands */
        $bands = [
            'lt_50' => ['offers' => 0, 'accepted' => 0],
            '50_99' => ['offers' => 0, 'accepted' => 0],
            'gte_100' => ['offers' => 0, 'accepted' => 0],
        ];

        foreach ($offers as $offer) {
            $optionTotal += $offer->options->count();
            $isAccepted = $offer->accepted_at !== null;
            if ($isAccepted) {
                $acceptedOffers++;
            }

            foreach ($offer->options as $option) {
                /** @var OfferOption $option */
                $amount = BillingMath::round2((string) ($option->unitClassRate?->price?->amount ?? '0'));
                $band = self::priceBand($amount);
                $bands[$band]['offers']++;
                if ($isAccepted && $option->selected_at !== null) {
                    $bands[$band]['accepted']++;
                }
            }
        }

        $bandRows = [];
        foreach ($bands as $key => $stats) {
            $bandRows[] = [
                'band' => $key,
                'options' => $stats['offers'],
                'accepted' => $stats['accepted'],
                'acceptance_rate' => $stats['offers'] > 0
                    ? round($stats['accepted'] / $stats['offers'], 4)
                    : null,
            ];
        }

        $stageCounts = [
            'deals' => $deals->count(),
            'offers_sent' => $dealsSent,
            'offers_viewed' => $dealsViewed,
            'accepted' => $dealsAccepted,
            'contracts_signed' => $dealsSigned,
        ];

        $prev = null;
        $rows = [];
        $labels = [
            'deals' => 'Deals',
            'offers_sent' => 'Offers sent',
            'offers_viewed' => 'Offers viewed',
            'accepted' => 'Accepted',
            'contracts_signed' => 'Contracts signed',
        ];
        foreach ($labels as $key => $label) {
            $count = $stageCounts[$key];
            $drop = $prev === null ? 0 : max(0, $prev - $count);
            $rows[] = [
                'stage' => $key,
                'label' => $label,
                'count' => $count,
                'drop_off' => $drop,
            ];
            $prev = $count;
        }

        $columns = [
            ReportColumn::string('stage', 'Stage'),
            ReportColumn::string('label', 'Label'),
            ReportColumn::int('count', 'Count'),
            ReportColumn::int('drop_off', 'Drop-off'),
        ];

        return new ReportResult($columns, $rows, [
            'from' => $fromDt->toDateString(),
            'to' => $toDt->toDateString(),
            'median_days' => [
                'deal_to_sent' => self::median($dealToSent),
                'sent_to_viewed' => self::median($sentToViewed),
                'viewed_to_accepted' => self::median($viewedToAccepted),
                'accepted_to_signed' => self::median($acceptedToSigned),
            ],
            'signature_split' => [
                'walk_in' => $signedWalkIn,
                'remote' => $signedRemote,
            ],
            'lead_chase' => [
                'correlation_caveat' => true,
                'caveat' => 'correlation_not_causation',
                'playbook_id' => $leadChasePlaybook?->id,
                'enrolled_deals' => $enrolledDeals,
                'not_enrolled_deals' => $notEnrolledDeals,
                'enrolled_converters' => $enrolledConverters,
                'not_enrolled_converters' => $notEnrolledConverters,
                'exits_by_cause' => $exitsByCause,
                'steps_received_converters' => $stepsReceivedConverters,
            ],
            'offer_performance' => [
                'offers' => $offerCount,
                'avg_options_per_offer' => $offerCount > 0
                    ? round($optionTotal / $offerCount, 2)
                    : null,
                'acceptance_rate' => $offerCount > 0
                    ? round($acceptedOffers / $offerCount, 4)
                    : null,
                'price_bands' => $bandRows,
            ],
            'definitions' => 'docs/report-definitions.md#funnel--embudo',
        ]);
    }

    /**
     * @param  list<float|int>  $values
     */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values, SORT_NUMERIC);
        $n = count($values);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) {
            return round((float) $values[$mid], 2);
        }

        return round(((float) $values[$mid - 1] + (float) $values[$mid]) / 2, 2);
    }

    private static function priceBand(string $amount): string
    {
        if (BillingMath::cmp($amount, '50.00') < 0) {
            return 'lt_50';
        }
        if (BillingMath::cmp($amount, '100.00') < 0) {
            return '50_99';
        }

        return 'gte_100';
    }
}
