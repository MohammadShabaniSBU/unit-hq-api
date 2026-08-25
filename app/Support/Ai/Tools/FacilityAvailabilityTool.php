<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Site;
use App\Models\Unit;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Occupancy\Availability;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;

final class FacilityAvailabilityTool implements AgentTool
{
    public function key(): string
    {
        return 'facility.availability';
    }

    public function description(): string
    {
        return 'Count currently available units by site and unit class. Returns counts and class labels, never unit identifiers. Availability is a snapshot as of now.';
    }

    public function schema(): array
    {
        return [
            'site_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Limit to one site',
            ],
            'unit_class_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Limit to one unit class',
            ],
            'min_area' => [
                'type' => 'number',
                'required' => false,
                'description' => 'Minimum class size in m²',
            ],
            'max_area' => [
                'type' => 'number',
                'required' => false,
                'description' => 'Maximum class size in m²',
            ],
            'from_date' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Civil date YYYY-MM-DD; omit for today per site',
            ],
        ];
    }

    public function requiredVerification(): VerificationLevel
    {
        return VerificationLevel::Anonymous;
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function contactScopedArgumentKeys(): array
    {
        return [];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $siteId = isset($arguments['site_id']) ? (int) $arguments['site_id'] : $principal->siteId;
        $classId = isset($arguments['unit_class_id']) ? (int) $arguments['unit_class_id'] : null;
        $minArea = isset($arguments['min_area']) ? (string) $arguments['min_area'] : null;
        $maxArea = isset($arguments['max_area']) ? (string) $arguments['max_area'] : null;
        $fromDate = isset($arguments['from_date']) ? (string) $arguments['from_date'] : null;

        $query = Unit::query()->where('enabled', true);
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }
        if ($classId !== null) {
            $query->where('unit_class_id', $classId);
        }
        if ($minArea !== null || $maxArea !== null) {
            $query->whereHas('unitClass', function ($inner) use ($minArea, $maxArea): void {
                if ($minArea !== null) {
                    $inner->where('size', '>=', $minArea);
                }
                if ($maxArea !== null) {
                    $inner->where('size', '<=', $maxArea);
                }
            });
        }

        if ($fromDate !== null && $fromDate !== '') {
            Availability::scopeAvailableOn($query, CarbonImmutable::parse($fromDate)->startOfDay());
        } else {
            Availability::scopeAvailableTodayPerSite($query);
        }

        $units = $query->with(['site:id,name', 'unitClass:id,label,size'])->get();

        $groups = [];
        foreach ($units as $unit) {
            $site = $unit->site;
            $class = $unit->unitClass;
            if ($site === null || $class === null) {
                continue;
            }
            $key = $site->id.'-'.$class->id;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'site_id' => $site->id,
                    'site_name' => $site->name,
                    'unit_class_id' => $class->id,
                    'label' => $class->label,
                    'size' => $class->size !== null ? (string) $class->size : null,
                    'count' => 0,
                ];
            }
            $groups[$key]['count']++;
        }

        $facts = new FactBag;
        $lines = [];
        $entities = [];
        $seen = [];
        foreach ($groups as $group) {
            $facts->number($group['count']);
            if ($group['size'] !== null) {
                $facts->number($group['size']);
                $sizeBit = " ({$group['size']} m²)";
            } else {
                $sizeBit = '';
            }
            $n = $group['count'];
            $unitWord = $n === 1 ? 'unit' : 'units';
            $lines[] = "{$n} {$unitWord} available in {$group['label']}{$sizeBit} at {$group['site_name']} as of now.";

            $siteKey = 'site:'.$group['site_id'];
            if (! isset($seen[$siteKey])) {
                $seen[$siteKey] = true;
                $entities[] = EntityRef::of(
                    EntityType::Site,
                    $group['site_id'],
                    $group['site_name'],
                );
            }
            $classKey = 'unit_class:'.$group['unit_class_id'].':'.$group['site_id'];
            if (! isset($seen[$classKey])) {
                $seen[$classKey] = true;
                $entities[] = EntityRef::of(
                    EntityType::UnitClass,
                    $group['unit_class_id'],
                    $group['label'],
                    $group['site_name'],
                );
            }
        }

        $asOf = 'now';
        if ($fromDate !== null && $fromDate !== '') {
            $asOf = $fromDate;
        } elseif ($siteId !== null) {
            $site = Site::query()->find($siteId);
            if ($site !== null) {
                $asOf = SiteClock::today($site)->toDateString();
            }
        }
        if ($asOf !== 'now') {
            $facts->date($asOf);
        }

        $display = $lines === []
            ? 'No units available matching those filters as of now.'
            : implode(' ', $lines);

        return ToolResult::ok(
            [
                'as_of' => $asOf,
                'classes' => array_values($groups),
            ],
            $display,
            $facts,
            entities: $entities,
        );
    }
}
