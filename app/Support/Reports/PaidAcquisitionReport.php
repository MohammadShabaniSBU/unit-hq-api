<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\ChargeType;
use App\Models\AdCampaign;
use App\Models\AdSpendDaily;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\MarketingTouch;
use App\Models\Site;
use App\Models\Unit;
use App\Support\Advertising\AdPlatform;
use App\Support\Advertising\AttributedTouch;
use App\Support\Billing\BillingMath;
use App\Support\Reports\Charts\CategoryKind;
use App\Support\Reports\Charts\ChartFormat;
use App\Support\Reports\Charts\ChartSeries;
use App\Support\Reports\Charts\ChartSeriesKind;
use App\Support\Reports\Charts\ChartSpec;
use App\Support\Reports\Charts\ChartType;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Paid acquisition: spend, leads, and move-ins by site and platform.
 * Attribution is computed per query. Money is never summed across currencies.
 * Definitions: docs/report-definitions.md — Paid acquisition section.
 */
final class PaidAcquisitionReport extends AbstractReport
{
    public static function name(): string
    {
        return 'paid-acquisition';
    }

    public function maxQueries(): int
    {
        return 30;
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $to = $filters->to ?? OccupancyMetrics::resolveAsOf($filters);
        $from = $filters->from ?? CarbonImmutable::parse($to)->subDays(90)->toDateString();
        $window = $filters->attributionWindow;
        $platform = $filters->platform !== null ? AdPlatform::from($filters->platform) : null;
        $providers = $platform !== null
            ? [$platform->value]
            : [AdPlatform::GoogleAds->value, AdPlatform::Meta->value];

        if ($filters->siteIds === []) {
            return $this->result($from, $to, $window, $platform, [], [], [], []);
        }

        /** @var Collection<int, Site> $sites */
        $sites = Site::query()->orderBy('name')->get()->keyBy(static fn (Site $site): int => (int) $site->id);
        $showUnmapped = $filters->siteIds === null || $this->coversEverySite($filters->siteIds, $sites);

        /** @var Collection<int, AdCampaign> $campaigns */
        $campaigns = AdCampaign::query()
            ->with('account:id,provider,currency,timezone')
            ->whereHas('account', static fn ($query) => $query->whereIn('provider', $providers))
            ->get()
            ->keyBy(static fn (AdCampaign $campaign): int => (int) $campaign->id);

        $spendRows = $campaigns->isEmpty()
            ? collect()
            : AdSpendDaily::query()
                ->whereBetween('spend_date', [$from, $to])
                ->whereIn('ad_campaign_id', $campaigns->keys()->all())
                ->get();

        /** @var array<string, string> $spend pair|currency => amount */
        $spend = [];
        /** @var array<string, string> $campaignSpend campaign|currency => amount */
        $campaignSpend = [];
        /** @var array<string, array<string, string>> $weeklySpend currency => week => amount */
        $weeklySpend = [];

        foreach ($spendRows as $row) {
            $campaign = $campaigns->get((int) $row->ad_campaign_id);
            if ($campaign === null || $campaign->account === null) {
                continue;
            }

            $provider = $campaign->account->provider instanceof AdPlatform
                ? $campaign->account->provider->value
                : (string) $campaign->account->provider;
            $currency = strtoupper((string) $row->currency);
            $amount = (string) $row->cost;
            $mapped = $campaign->site_id !== null;
            $siteId = $mapped ? (int) $campaign->site_id : null;

            if ($mapped && ! $this->inScope($siteId, $filters->siteIds)) {
                continue;
            }
            if (! $mapped && ! $showUnmapped) {
                continue;
            }

            $pair = self::pair($siteId, $provider);
            $spendKey = $pair.'|'.$currency;
            $spend[$spendKey] = self::add($spend[$spendKey] ?? '0.00', $amount);

            $campaignKey = $campaign->id.'|'.$currency;
            $campaignSpend[$campaignKey] = self::add($campaignSpend[$campaignKey] ?? '0.00', $amount);

            $week = CarbonImmutable::parse($row->spend_date)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            $weeklySpend[$currency][$week] = self::add($weeklySpend[$currency][$week] ?? '0.00', $amount);
        }

        $padFrom = CarbonImmutable::parse($from)->subDays(2)->startOfDay();
        $padTo = CarbonImmutable::parse($to)->addDays(2)->endOfDay();

        $deals = $this->dealsInRange($filters, $sites, $from, $to, $padFrom, $padTo);
        $dealTouches = AttributedTouch::latestPaid(
            $this->subjects($deals, static fn (Deal $deal): CarbonImmutable => CarbonImmutable::parse($deal->created_at)),
            $window,
            $platform,
        );

        /** @var array<string, int> $leads */
        $leads = [];
        /** @var array<string, int> $campaignLeads */
        $campaignLeads = [];
        foreach ($deals as $deal) {
            $touch = $dealTouches[(int) $deal->id] ?? null;
            if (! $touch instanceof MarketingTouch || $touch->platform === null) {
                continue;
            }

            $pair = self::pair((int) $deal->site_id, $touch->platform->value);
            $leads[$pair] = ($leads[$pair] ?? 0) + 1;

            $matched = $this->matchCampaign($touch, $campaigns);
            if ($matched !== null) {
                $campaignLeads[(string) $matched->id] = ($campaignLeads[(string) $matched->id] ?? 0) + 1;
            }
        }

        $moveIns = $this->moveInsInRange($filters, $sites, $from, $to, $padFrom, $padTo);
        $moveInTouches = AttributedTouch::latestPaid(
            $this->contractSubjects($moveIns),
            $window,
            $platform,
        );

        $paidContracts = [];
        foreach ($moveIns as $contract) {
            $touch = $moveInTouches[(int) $contract->id] ?? null;
            if ($touch instanceof MarketingTouch && $touch->platform !== null) {
                $paidContracts[] = ['contract' => $contract, 'touch' => $touch];
            }
        }

        $nets = $this->firstPeriodNets(array_map(
            static fn (array $row): int => (int) $row['contract']->id,
            $paidContracts,
        ));

        /** @var array<string, int> $moveInCounts */
        $moveInCounts = [];
        /** @var array<string, array<string, string>> $revenue pair => currency => net */
        $revenue = [];
        /** @var array<string, int> $campaignMoveIns */
        $campaignMoveIns = [];
        /** @var array<string, array<string, string>> $campaignRevenue campaign id => currency => net */
        $campaignRevenue = [];
        /** @var array<string, array<string, int>> $weeklyMoveIns currency => week => count */
        $weeklyMoveIns = [];

        foreach ($paidContracts as $row) {
            /** @var Contract $contract */
            $contract = $row['contract'];
            /** @var MarketingTouch $touch */
            $touch = $row['touch'];
            $siteId = $this->contractSiteId($contract);
            if ($siteId === null) {
                continue;
            }

            $provider = $touch->platform instanceof AdPlatform
                ? $touch->platform->value
                : (string) $touch->platform;
            $pair = self::pair($siteId, $provider);
            $moveInCounts[$pair] = ($moveInCounts[$pair] ?? 0) + 1;

            $contractCurrency = strtoupper((string) $contract->currency);
            $revenue[$pair][$contractCurrency] = self::add(
                $revenue[$pair][$contractCurrency] ?? '0.00',
                $nets[(int) $contract->id] ?? '0.00',
            );

            $site = $sites->get($siteId);
            if ($site !== null && $contract->signed_at !== null) {
                $civil = SiteClock::dateAt($site, CarbonImmutable::parse($contract->signed_at));
                $week = $civil->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
                $chartCurrencies = $this->currenciesForPair($pair, $spend);
                if ($chartCurrencies === []) {
                    $chartCurrencies = [$contractCurrency];
                }
                foreach ($chartCurrencies as $chartCurrency) {
                    $weeklyMoveIns[$chartCurrency][$week] = ($weeklyMoveIns[$chartCurrency][$week] ?? 0) + 1;
                }
            }

            $matched = $this->matchCampaign($touch, $campaigns);
            if ($matched !== null) {
                $campaignId = (string) $matched->id;
                $campaignMoveIns[$campaignId] = ($campaignMoveIns[$campaignId] ?? 0) + 1;
                $campaignRevenue[$campaignId][$contractCurrency] = self::add(
                    $campaignRevenue[$campaignId][$contractCurrency] ?? '0.00',
                    $nets[(int) $contract->id] ?? '0.00',
                );
            }
        }

        $rows = $this->siteRows($spend, $leads, $moveInCounts, $revenue, $sites, $showUnmapped);
        $kpis = $this->kpis($rows);
        $campaignRows = $this->campaignRows(
            $campaigns,
            $campaignSpend,
            $campaignLeads,
            $campaignMoveIns,
            $campaignRevenue,
            $sites,
        );
        $charts = $this->charts($from, $to, $weeklySpend, $weeklyMoveIns);

        return $this->result($from, $to, $window, $platform, $rows, $kpis, $campaignRows, $charts);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $kpis
     * @param  list<array<string, mixed>>  $campaigns
     * @param  list<ChartSpec>  $charts
     */
    private function result(
        string $from,
        string $to,
        int $window,
        ?AdPlatform $platform,
        array $rows,
        array $kpis,
        array $campaigns,
        array $charts,
    ): ReportResult {
        return new ReportResult(
            columns: $this->columns($rows),
            rows: $rows,
            meta: [
                'from' => $from,
                'to' => $to,
                'attribution_window' => $window,
                'platform' => $platform?->value ?? 'all',
                'kpis' => $kpis,
                'campaigns' => $campaigns,
                'notes' => [
                    'Spend dates are civil dates in the ad account timezone. Leads and move-ins use the site timezone. At day granularity across zones the edges can shift by one day.',
                ],
                'definitions' => 'docs/report-definitions.md#paid-acquisition--adquisición-de-pago',
            ],
            charts: $charts,
        );
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    private function coversEverySite(array $requested, Collection $sites): bool
    {
        if ($sites->isEmpty()) {
            return true;
        }

        foreach ($sites as $site) {
            if (! in_array((int) $site->id, $requested, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<int>|null  $siteIds
     */
    private function inScope(?int $siteId, ?array $siteIds): bool
    {
        if ($siteId === null) {
            return false;
        }

        return $siteIds === null || in_array($siteId, $siteIds, true);
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return Collection<int, Deal>
     */
    private function dealsInRange(
        ReportFilters $filters,
        Collection $sites,
        string $from,
        string $to,
        CarbonImmutable $padFrom,
        CarbonImmutable $padTo,
    ): Collection {
        return Deal::query()
            ->whereNotNull('site_id')
            ->when($filters->siteIds !== null, static fn ($query) => $query->whereIn('site_id', $filters->siteIds))
            ->whereBetween('created_at', [$padFrom->toDateTimeString(), $padTo->toDateTimeString()])
            ->orderBy('id')
            ->get()
            ->filter(function (Deal $deal) use ($sites, $from, $to): bool {
                $site = $sites->get((int) $deal->site_id);

                return $site !== null && $this->instantInCivilRange(CarbonImmutable::parse($deal->created_at), $site, $from, $to);
            })
            ->values();
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return Collection<int, Contract>
     */
    private function moveInsInRange(
        ReportFilters $filters,
        Collection $sites,
        string $from,
        string $to,
        CarbonImmutable $padFrom,
        CarbonImmutable $padTo,
    ): Collection {
        return Contract::query()
            ->whereNotNull('signed_at')
            ->whereNotNull('deal_id')
            ->whereBetween('signed_at', [$padFrom->toDateTimeString(), $padTo->toDateTimeString()])
            ->with(['deal:id,contact_id,site_id,created_at', 'unitItem.item'])
            ->orderBy('id')
            ->get()
            ->filter(function (Contract $contract) use ($filters, $sites, $from, $to): bool {
                $siteId = $this->contractSiteId($contract);
                if (! $this->inScope($siteId, $filters->siteIds)) {
                    return false;
                }

                $site = $sites->get((int) $siteId);

                return $site !== null
                    && $contract->signed_at !== null
                    && $this->instantInCivilRange(CarbonImmutable::parse($contract->signed_at), $site, $from, $to);
            })
            ->values();
    }

    private function instantInCivilRange(CarbonImmutable $instant, Site $site, string $from, string $to): bool
    {
        $start = SiteClock::startOfDay($site, CarbonImmutable::parse($from));
        $end = SiteClock::startOfDay($site, CarbonImmutable::parse($to))->endOfDay();

        return $instant->greaterThanOrEqualTo($start) && $instant->lessThanOrEqualTo($end);
    }

    /**
     * @param  Collection<int, Deal>  $deals
     * @param  callable(Deal): CarbonImmutable  $anchor
     * @return list<array{id: int, contact_id: int|null, anchor: CarbonImmutable}>
     */
    private function subjects(Collection $deals, callable $anchor): array
    {
        $subjects = [];
        foreach ($deals as $deal) {
            $subjects[] = [
                'id' => (int) $deal->id,
                'contact_id' => (int) $deal->contact_id,
                'anchor' => $anchor($deal),
            ];
        }

        return $subjects;
    }

    /**
     * @param  Collection<int, Contract>  $contracts
     * @return list<array{id: int, contact_id: int|null, anchor: CarbonImmutable}>
     */
    private function contractSubjects(Collection $contracts): array
    {
        $subjects = [];
        foreach ($contracts as $contract) {
            if ($contract->deal === null) {
                continue;
            }

            $subjects[] = [
                'id' => (int) $contract->id,
                'contact_id' => (int) $contract->contact_id,
                'anchor' => CarbonImmutable::parse($contract->deal->created_at),
            ];
        }

        return $subjects;
    }

    private function contractSiteId(Contract $contract): ?int
    {
        $unit = $contract->unitItem?->item;
        if ($unit instanceof Unit) {
            return (int) $unit->site_id;
        }

        $dealSite = $contract->deal?->site_id;

        return $dealSite !== null ? (int) $dealSite : null;
    }

    /**
     * @param  Collection<int, AdCampaign>  $campaigns
     */
    private function matchCampaign(MarketingTouch $touch, Collection $campaigns): ?AdCampaign
    {
        $utm = $touch->utm_campaign;
        if ($utm === null || $utm === '') {
            return null;
        }

        /** @var AdCampaign|null $hint */
        $hint = $campaigns
            ->filter(static fn (AdCampaign $campaign): bool => $campaign->utm_campaign_hint === $utm)
            ->sortBy('id')
            ->first();
        if ($hint !== null) {
            return $hint;
        }

        /** @var AdCampaign|null $byId */
        $byId = $campaigns
            ->filter(function (AdCampaign $campaign) use ($touch, $utm): bool {
                $provider = $campaign->account?->provider;

                return $campaign->external_id === $utm && $provider === $touch->platform;
            })
            ->sortBy('id')
            ->first();

        return $byId;
    }

    /**
     * @param  list<int>  $contractIds
     * @return array<int, string>
     */
    private function firstPeriodNets(array $contractIds): array
    {
        if ($contractIds === []) {
            return [];
        }

        $grouped = Charge::query()
            ->whereIn('contract_id', $contractIds)
            ->get()
            ->groupBy(static fn (Charge $charge): int => (int) $charge->contract_id);

        $nets = [];
        foreach ($contractIds as $contractId) {
            $nets[$contractId] = $this->firstPeriodNet($grouped->get($contractId, collect()));
        }

        return $nets;
    }

    /**
     * @param  Collection<int, Charge>  $charges
     */
    private function firstPeriodNet(Collection $charges): string
    {
        $revenue = [];
        foreach ($charges as $charge) {
            $type = $charge->charge_type instanceof ChargeType
                ? $charge->charge_type
                : ChargeType::tryFrom((string) $charge->charge_type);
            if ($type === null || ! $type->isRevenue()) {
                continue;
            }
            $revenue[] = $charge;
        }

        if ($revenue === []) {
            return '0.00';
        }

        $dated = array_values(array_filter(
            $revenue,
            static fn (Charge $charge): bool => $charge->period_start !== null,
        ));
        $pool = $dated !== [] ? $dated : $revenue;
        $min = null;
        foreach ($pool as $charge) {
            $start = $this->periodStart($charge);
            if ($start !== null && ($min === null || $start < $min)) {
                $min = $start;
            }
        }

        $sum = '0.00';
        foreach ($pool as $charge) {
            $start = $this->periodStart($charge);
            if ($dated !== [] && $start !== $min) {
                continue;
            }
            $sum = self::add($sum, (string) $charge->net_amount);
        }

        return $sum;
    }

    private function periodStart(Charge $charge): ?string
    {
        if ($charge->period_start === null) {
            return null;
        }

        return CarbonImmutable::parse($charge->period_start)->toDateString();
    }

    /**
     * @param  array<string, string>  $spend
     * @param  array<string, int>  $leads
     * @param  array<string, int>  $moveIns
     * @param  array<string, array<string, string>>  $revenue
     * @param  Collection<int, Site>  $sites
     * @return list<array<string, mixed>>
     */
    private function siteRows(
        array $spend,
        array $leads,
        array $moveIns,
        array $revenue,
        Collection $sites,
        bool $showUnmapped,
    ): array {
        /** @var array<string, true> $keys */
        $keys = [];
        foreach ($spend as $key => $amount) {
            if (! $showUnmapped && str_starts_with($key, 'unmapped|')) {
                continue;
            }
            $keys[$key] = true;
        }

        foreach ([$leads, $moveIns] as $counts) {
            foreach ($counts as $pair => $count) {
                if ($count === 0 || str_starts_with($pair, 'unmapped|')) {
                    continue;
                }
                $currencies = $this->currenciesForPair($pair, $spend);
                if ($currencies === []) {
                    $siteId = (int) explode('|', $pair, 2)[0];
                    $currency = strtoupper((string) ($sites->get($siteId)?->currency ?? ''));
                    if ($currency !== '') {
                        $keys[$pair.'|'.$currency] = true;
                    }

                    continue;
                }
                foreach ($currencies as $currency) {
                    $keys[$pair.'|'.$currency] = true;
                }
            }
        }

        $rows = [];
        foreach (array_keys($keys) as $key) {
            [$sitePart, $provider, $currency] = explode('|', $key);
            $pair = $sitePart.'|'.$provider;
            $siteId = $sitePart === 'unmapped' ? null : (int) $sitePart;
            $rowSpend = $spend[$key] ?? '0.00';
            $rowLeads = $leads[$pair] ?? 0;
            $rowMoveIns = $moveIns[$pair] ?? 0;
            $measures = $this->measuresForCurrency($rowSpend, $currency, $rowLeads, $rowMoveIns, $revenue[$pair] ?? []);

            $rows[] = [
                'row_kind' => $siteId === null ? 'unmapped' : 'site',
                'site_id' => $siteId,
                'site' => $siteId === null ? null : (string) ($sites->get($siteId)?->name ?? ''),
                'platform' => $provider,
                'currency' => $currency,
                'spend' => $rowSpend,
                'paid_leads' => $rowLeads,
                'paid_move_ins' => $rowMoveIns,
                'cost_per_lead' => $measures['cost_per_lead'],
                'cost_per_move_in' => $measures['cost_per_move_in'],
                'roas' => $measures['roas'],
                'roas_reason' => $measures['roas_reason'],
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            $kind = ($left['row_kind'] === 'unmapped' ? 1 : 0) <=> ($right['row_kind'] === 'unmapped' ? 1 : 0);
            if ($kind !== 0) {
                return $kind;
            }

            return [$left['site'] ?? '', $left['platform'], $left['currency']]
                <=> [$right['site'] ?? '', $right['platform'], $right['currency']];
        });

        return $rows;
    }

    /**
     * KPI spend is mapped spend only. Unmapped spend stays on its own row.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function kpis(array $rows): array
    {
        /** @var array<string, array{spend: string, paid_leads: int, paid_move_ins: int}> $byCurrency */
        $byCurrency = [];
        foreach ($rows as $row) {
            if ($row['row_kind'] === 'unmapped') {
                continue;
            }

            $currency = (string) $row['currency'];
            if (! isset($byCurrency[$currency])) {
                $byCurrency[$currency] = [
                    'spend' => '0.00',
                    'paid_leads' => 0,
                    'paid_move_ins' => 0,
                ];
            }
            $byCurrency[$currency]['spend'] = self::add($byCurrency[$currency]['spend'], (string) $row['spend']);
            $byCurrency[$currency]['paid_leads'] += (int) $row['paid_leads'];
            $byCurrency[$currency]['paid_move_ins'] += (int) $row['paid_move_ins'];
        }

        ksort($byCurrency);
        $kpis = [];
        foreach ($byCurrency as $currency => $bucket) {
            $kpis[] = [
                'currency' => $currency,
                'spend' => $bucket['spend'],
                'paid_leads' => $bucket['paid_leads'],
                'paid_move_ins' => $bucket['paid_move_ins'],
                'cost_per_lead' => $bucket['paid_leads'] === 0
                    ? null
                    : self::ratio($bucket['spend'], $bucket['paid_leads']),
                'cost_per_move_in' => $bucket['paid_move_ins'] === 0
                    ? null
                    : self::ratio($bucket['spend'], $bucket['paid_move_ins']),
            ];
        }

        return $kpis;
    }

    /**
     * @param  Collection<int, AdCampaign>  $campaigns
     * @param  array<string, string>  $campaignSpend
     * @param  array<string, int>  $campaignLeads
     * @param  array<string, int>  $campaignMoveIns
     * @param  array<string, array<string, string>>  $campaignRevenue
     * @param  Collection<int, Site>  $sites
     * @return list<array<string, mixed>>
     */
    private function campaignRows(
        Collection $campaigns,
        array $campaignSpend,
        array $campaignLeads,
        array $campaignMoveIns,
        array $campaignRevenue,
        Collection $sites,
    ): array {
        /** @var array<string, true> $keys */
        $keys = [];
        foreach ($campaignSpend as $key => $amount) {
            $keys[$key] = true;
        }
        foreach ([$campaignLeads, $campaignMoveIns] as $counts) {
            foreach ($counts as $campaignId => $count) {
                if ($count === 0) {
                    continue;
                }
                $campaign = $campaigns->get((int) $campaignId);
                $currency = strtoupper((string) ($campaign?->account?->currency ?? ''));
                if ($currency === '') {
                    $siteId = $campaign?->site_id;
                    $currency = strtoupper((string) ($siteId !== null ? ($sites->get((int) $siteId)?->currency ?? '') : ''));
                }
                if ($currency !== '') {
                    $keys[$campaignId.'|'.$currency] = true;
                }
            }
        }

        $rows = [];
        foreach (array_keys($keys) as $key) {
            [$campaignId, $currency] = explode('|', $key);
            $campaign = $campaigns->get((int) $campaignId);
            if ($campaign === null || $campaign->account === null) {
                continue;
            }

            $provider = $campaign->account->provider instanceof AdPlatform
                ? $campaign->account->provider->value
                : (string) $campaign->account->provider;
            $siteId = $campaign->site_id !== null ? (int) $campaign->site_id : null;
            $rowSpend = $campaignSpend[$key] ?? '0.00';
            $rowLeads = $campaignLeads[$campaignId] ?? 0;
            $rowMoveIns = $campaignMoveIns[$campaignId] ?? 0;
            $measures = $this->measuresForCurrency($rowSpend, $currency, $rowLeads, $rowMoveIns, $campaignRevenue[$campaignId] ?? []);

            $rows[] = [
                'campaign_id' => (int) $campaignId,
                'campaign' => $campaign->name,
                'platform' => $provider,
                'site_id' => $siteId,
                'site' => $siteId === null ? null : (string) ($sites->get($siteId)?->name ?? ''),
                'mapping_status' => $siteId === null ? 'unmapped' : 'mapped',
                'currency' => $currency,
                'spend' => $rowSpend,
                'paid_leads' => $rowLeads,
                'paid_move_ins' => $rowMoveIns,
                'cost_per_lead' => $measures['cost_per_lead'],
                'cost_per_move_in' => $measures['cost_per_move_in'],
                'roas' => $measures['roas'],
                'roas_reason' => $measures['roas_reason'],
            ];
        }

        usort($rows, static fn (array $left, array $right): int => [$left['campaign'], $left['currency']] <=> [$right['campaign'], $right['currency']]);

        return $rows;
    }

    /**
     * @param  array<string, string>  $revenueByCurrency
     * @return array{cost_per_lead: string|null, cost_per_move_in: string|null, roas: string|null, roas_reason: string|null}
     */
    private function measuresForCurrency(string $spend, string $currency, int $leads, int $moveIns, array $revenueByCurrency): array
    {
        $mismatch = false;
        foreach ($revenueByCurrency as $revenueCurrency => $amount) {
            if (strtoupper((string) $revenueCurrency) !== $currency) {
                $mismatch = true;
            }
        }

        $revenue = $mismatch ? '0.00' : ($revenueByCurrency[$currency] ?? '0.00');
        $roas = $this->roas($spend, $revenue, $mismatch);

        return [
            'cost_per_lead' => $leads === 0 ? null : self::ratio($spend, $leads),
            'cost_per_move_in' => $moveIns === 0 ? null : self::ratio($spend, $moveIns),
            'roas' => $roas['roas'],
            'roas_reason' => $roas['reason'],
        ];
    }

    /**
     * @return array{roas: string|null, reason: string|null}
     */
    private function roas(string $spend, string $revenue, bool $mismatch): array
    {
        if (BillingMath::cmp($spend, '0') === 0) {
            return ['roas' => null, 'reason' => 'no_spend'];
        }

        if ($mismatch) {
            return ['roas' => null, 'reason' => 'currency_mismatch'];
        }

        return [
            'roas' => BillingMath::round2(bcdiv($revenue, $spend, 8)),
            'reason' => null,
        ];
    }

    /**
     * @param  array<string, string>  $spend
     * @return list<string>
     */
    private function currenciesForPair(string $pair, array $spend): array
    {
        $currencies = [];
        $prefix = $pair.'|';
        foreach (array_keys($spend) as $key) {
            if (str_starts_with($key, $prefix)) {
                $currencies[] = substr($key, strlen($prefix));
            }
        }

        return array_values(array_unique($currencies));
    }

    /**
     * @param  array<string, array<string, string>>  $weeklySpend
     * @param  array<string, array<string, int>>  $weeklyMoveIns
     * @return list<ChartSpec>
     */
    private function charts(string $from, string $to, array $weeklySpend, array $weeklyMoveIns): array
    {
        $weeks = [];
        $cursor = CarbonImmutable::parse($from)->startOfWeek(CarbonImmutable::MONDAY);
        $end = CarbonImmutable::parse($to)->startOfWeek(CarbonImmutable::MONDAY);
        while ($cursor->lessThanOrEqualTo($end)) {
            $weeks[] = $cursor->toDateString();
            $cursor = $cursor->addWeek();
        }

        if ($weeks === []) {
            return [];
        }

        $currencies = array_values(array_unique([
            ...array_keys($weeklySpend),
            ...array_keys($weeklyMoveIns),
        ]));
        sort($currencies);

        $charts = [];
        foreach ($currencies as $currency) {
            $spendData = [];
            $moveInData = [];
            foreach ($weeks as $week) {
                $spendData[] = $weeklySpend[$currency][$week] ?? '0.00';
                $moveInData[] = $weeklyMoveIns[$currency][$week] ?? 0;
            }

            $charts[] = new ChartSpec(
                key: 'spend_vs_move_ins:'.$currency,
                type: ChartType::Combo,
                titleKey: 'insights.charts.paid_acquisition_trend.title',
                descriptionKey: 'insights.charts.paid_acquisition_trend.description',
                categories: $weeks,
                categoryKind: CategoryKind::Text,
                series: [
                    ChartSeries::keyed('insights.charts.series.spend', $spendData, ChartSeriesKind::Column, 0),
                    ChartSeries::keyed('insights.charts.series.paid_move_ins', $moveInData, ChartSeriesKind::Line, 1),
                ],
                format: ChartFormat::Money,
                secondaryFormat: ChartFormat::Int,
                currency: $currency,
            );
        }

        return $charts;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<ReportColumn>
     */
    private function columns(array $rows): array
    {
        $currencies = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['currency'],
            $rows,
        )));
        $single = count($currencies) === 1 ? $currencies[0] : null;

        $amount = function (string $key, string $label) use ($single): ReportColumn {
            return $single !== null
                ? ReportColumn::money($key, $label, $single)
                : ReportColumn::string($key, $label);
        };

        $columns = [
            ReportColumn::string('site', 'Site'),
            ReportColumn::string('platform', 'Platform'),
        ];
        if ($single === null) {
            $columns[] = ReportColumn::string('currency', 'Currency');
        }

        return [
            ...$columns,
            $amount('spend', 'Spend'),
            ReportColumn::int('paid_leads', 'Paid leads'),
            ReportColumn::int('paid_move_ins', 'Paid move-ins'),
            $amount('cost_per_lead', 'Cost per lead'),
            $amount('cost_per_move_in', 'Cost per move-in'),
            ReportColumn::string('roas', 'ROAS'),
        ];
    }

    private static function pair(?int $siteId, string $platform): string
    {
        return ($siteId === null ? 'unmapped' : (string) $siteId).'|'.$platform;
    }

    private static function add(string $left, string $right): string
    {
        return BillingMath::round2(bcadd($left, $right, 8));
    }

    private static function ratio(string $numerator, int $denominator): string
    {
        return BillingMath::round2(bcdiv($numerator, (string) $denominator, 8));
    }
}
