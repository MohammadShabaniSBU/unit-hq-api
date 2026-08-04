<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\UnitState;
use App\Models\Delinquency;
use App\Models\Unit;
use App\Models\UnitOccupancy;
use App\Support\Billing\BillingMath;
use App\Support\Delinquency\DelinquencyState;
use App\Support\Occupancy\Availability;
use App\Support\Reports\AgeingReport;
use App\Support\Reports\OccupancyMetrics;
use App\Support\Reports\OccupancyReport;
use App\Support\Reports\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/**
 * Presenter sheet for the seeded demo world — cast index, tour, live numbers.
 */
final class DemoScript
{
    /**
     * @var array<string, array{name: string, story: string, path: string}>
     */
    private const CAST = [
        'marcus' => [
            'name' => 'Marcus Webb',
            'story' => 'Upsize transfer with retained rate (+€40 after size-question SMS)',
            'path' => 'Contacts → Marcus Webb → Contracts tab — two occupancies on one contract',
        ],
        'lucia' => [
            'name' => 'Lucía Ferrer',
            'story' => 'Day-14+ delinquency, overlocked, door denied, still owing',
            'path' => 'Delinquency board → Lucía row → case timeline — point at denied-entry Interaction',
        ],
        'tom' => [
            'name' => 'Tom Bradley',
            'story' => 'Promise-keeper: wrap-up payment_promised, paid day 4 — cured history',
            'path' => 'Delinquency → cured / collections — promise-kept rate',
        ],
        'amara' => [
            'name' => 'Amara Okafor',
            'story' => 'Long-stay free weeks: walk-in ~3w before seed-end, still in €0 window on rent roll',
            'path' => 'Contacts → Amara Okafor → contract / rent roll',
        ],
        'jean_luc' => [
            'name' => 'Jean-Luc Perrin',
            'story' => 'Awaiting contract, envelope declined with reason',
            'path' => 'Contracts → Awaiting tab — declined chip',
        ],
        'sofia' => [
            'name' => 'Sofía Marín',
            'story' => 'Awaiting-expiring: viewed recently, amber ≤3d expiry',
            'path' => 'Contracts → Awaiting tab — expiring row',
        ],
        'derek' => [
            'name' => 'Derek Hoyle',
            'story' => '60+ → write-off → ended involuntary',
            'path' => 'Contracts → Ended — involuntary + write-off',
        ],
        'pilar' => [
            'name' => 'Pilar Santos',
            'story' => 'WhatsApp window dance — open window at seed-end',
            'path' => 'Inbox → Pilar Santos — open WhatsApp window near top',
        ],
        'hannah' => [
            'name' => 'Hannah Cole',
            'story' => 'Autopay failing: insufficient_funds ×2, amber chip',
            'path' => 'Contacts → Hannah Cole → contract autopay attempts',
        ],
        'rafa' => [
            'name' => 'Rafa Núñez',
            'story' => 'Payment-link lifecycle: SMS link → paid via Stripe webhook',
            'path' => 'Inbox / Rafa thread — request-payment round trip (paid)',
        ],
        'ingrid' => [
            'name' => 'Ingrid Weiss',
            'story' => 'Notice-given, move-out next week',
            'path' => 'Contracts → Notice tab',
        ],
        'omar' => [
            'name' => 'Omar Haddad',
            'story' => 'Pending: signed walk-in, move-in after seed-end',
            'path' => 'Contracts → Pending tab',
        ],
        'grace' => [
            'name' => 'Grace Lin',
            'story' => 'Funnel mid: offer viewed, lead-chase enrolment live',
            'path' => 'Funnel / open deal + enrolment',
        ],
        'bea' => [
            'name' => 'Bea Torres',
            'story' => 'Hard bounce → suppressed email, SMS fallback',
            'path' => 'Inbox → suppressed badge / bounced thread',
        ],
        'viktor' => [
            'name' => 'Viktor Palenik',
            'story' => 'Cancelled never-moved-in + lost deal',
            'path' => 'Contracts → Cancelled; Contacts → lost',
        ],
        'nadia' => [
            'name' => 'Nadia Rahal',
            'story' => '20% tracking discount across an applied rate change + one scheduled ~2 months out',
            'path' => 'Contacts → Nadia → rate-change / notices',
        ],
        'kellys' => [
            'name' => 'The Kellys (Pat Kelly)',
            'story' => 'Two units, one contact; vacated unit with deposit deduction',
            'path' => 'Contacts → Pat Kelly → multi-contract + settlement',
        ],
        'front_desk' => [
            'name' => 'Front-desk misc',
            'story' => 'Voicemail, triage strangers, wrong-number, unread staging',
            'path' => 'Inbox → triage queue + call textures',
        ],
    ];

    public static function render(): string
    {
        $asOf = CastExecutor::SIM_END;
        $numbers = self::liveNumbers($asOf);

        $lines = [
            '# Demo script',
            '',
            'Seed window: `'.CastExecutor::SIM_START.'` → `'.CastExecutor::SIM_END.'`.',
            'Generated from the live database after `php artisan demo:seed`.',
            '',
            '## Cast index',
            '',
        ];

        foreach (CastExecutor::CAST as $class) {
            $handle = $class::handle();
            $entry = self::CAST[$handle] ?? [
                'name' => $handle,
                'story' => '(see journey docblock)',
                'path' => 'Contacts',
            ];
            $lines[] = '- **'.$entry['name'].'** — '.$entry['story'].' → '.$entry['path'].'.';
        }

        $lines = array_merge($lines, [
            '',
            '## Employee grants',
            '',
            '| Email | Name | Role | Site |',
            '|---|---|---|---|',
        ]);

        foreach (DemoRbacGrants::grantTableRows() as $row) {
            $lines[] = '| `'.$row['email'].'` | '.$row['name'].' | `'.$row['role'].'` | '.$row['site'].' |';
        }

        $lines = array_merge($lines, [
            '',
            'Password for every demo employee: `password`.',
            '',
            '## 15-minute tour',
            '',
            '1. **Dashboard** — point at the economic vs unit occupancy gap (unit rate '.$numbers['unit_rate'].' vs economic '.$numbers['economic_rate'].').',
            '2. **Inbox** — the 7 unread, Pilar\'s open WhatsApp window, Rafa\'s request-payment thread (paid round trip).',
            '3. **Delinquency board** — open cases across every ageing bucket; chip total '.$numbers['ageing_chip'].'.',
            '4. **Lucía Ferrer\'s case** — timeline with denied-entry Interaction; overlock on the unit.',
            '5. **Unit map** — overlock glyphs on delinquent units.',
            '6. **Rent roll** — occupied rows feeding the monthly-rent card.',
            '7. **Funnel** — walk-in vs remote split; Grace Lin mid-chase.',
            '',
            '## Numbers that must match',
            '',
            '| Surface | Value |',
            '|---|---|',
            '| Occupancy report (occupied units) | '.$numbers['occ_report'].' |',
            '| Occupancy metrics snapshot | '.$numbers['occ_metrics'].' |',
            '| Units list (state=occupied) | '.$numbers['occ_units_list'].' |',
            '| Occupancy matrix (distinct occupied units) | '.$numbers['occ_matrix'].' |',
            '| Ageing report grand total (all currencies) | '.$numbers['ageing_report'].' |',
            '| Board overdue chip (open cases) | '.$numbers['ageing_chip'].' |',
            '| Unit occupancy rate | '.$numbers['unit_rate'].' |',
            '| Economic occupancy rate | '.$numbers['economic_rate'].' |',
            '',
            'While presenting: the three occupancy occupied counts must agree; the board chip must match open-case ageing.',
            '',
            'As-of: `'.$asOf.'`.',
            '',
            '## PR / walk checklist',
            '',
            '- [ ] Walk every cast click-path above once on a fresh seed',
            '- [ ] Time the 15-minute tour end-to-end',
            '- [ ] Confirm the three occupancy numbers agree while presenting',
            '- [ ] Confirm open-case ageing equals the delinquency board chip',
            '- [ ] Log in as `agent-mad@example.com` and confirm the contract list is a site slice of the owner view',
            '',
        ]);

        return implode("\n", $lines);
    }

    /**
     * @return string Absolute path written
     */
    public static function write(?string $path = null, ?string $contents = null): string
    {
        $path ??= storage_path('demo-script.md');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents ?? self::render());

        return $path;
    }

    /**
     * @return array{
     *     occ_report: int,
     *     occ_metrics: int,
     *     occ_units_list: int,
     *     occ_matrix: int,
     *     ageing_report: string,
     *     ageing_chip: string,
     *     unit_rate: string,
     *     economic_rate: string
     * }
     */
    public static function liveNumbers(string $asOf): array
    {
        $filters = new ReportFilters(asOf: $asOf);
        $occReport = (new OccupancyReport)->run($filters);
        $snap = OccupancyMetrics::snapshot($asOf);
        $on = CarbonImmutable::parse($asOf)->startOfDay();

        $listOccupied = Unit::query()
            ->where('enabled', true)
            ->tap(static fn ($q) => Availability::scopeStateOn($q, UnitState::Occupied, $on))
            ->count();

        $matrixOccupied = UnitOccupancy::query()
            ->where('started_on', '<=', $asOf)
            ->where(static function ($q) use ($asOf): void {
                $q->whereNull('ended_on')->orWhere('ended_on', '>', $asOf);
            })
            ->pluck('unit_id')
            ->unique()
            ->count();

        $ageing = (new AgeingReport)->run($filters);
        $ageingTotal = '0.00';
        foreach ($ageing->meta['totals_by_currency'] as $row) {
            $ageingTotal = BillingMath::round2(bcadd($ageingTotal, (string) $row['amount'], 2));
        }

        $chipTotal = '0.00';
        $cases = Delinquency::query()->open()->with('contract.charges.allocations')->get();
        foreach ($cases as $case) {
            $contract = $case->contract;
            if ($contract === null) {
                continue;
            }
            foreach (DelinquencyState::overdueCharges($contract, $on) as $charge) {
                $chipTotal = BillingMath::round2(bcadd($chipTotal, $charge->openAmount(), 2));
            }
        }

        $unitRate = $occReport->meta['headlines']['unit']['rate'] ?? null;
        $econRate = $occReport->meta['headlines']['economic']['rate'] ?? null;

        return [
            'occ_report' => (int) ($occReport->meta['headlines']['unit']['occupied'] ?? 0),
            'occ_metrics' => (int) $snap['occupied_units'],
            'occ_units_list' => $listOccupied,
            'occ_matrix' => $matrixOccupied,
            'ageing_report' => $ageingTotal,
            'ageing_chip' => $chipTotal,
            'unit_rate' => $unitRate === null ? 'n/a' : (string) $unitRate,
            'economic_rate' => $econRate === null ? 'n/a' : (string) $econRate,
        ];
    }
}
