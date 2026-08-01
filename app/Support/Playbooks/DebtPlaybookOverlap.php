<?php

declare(strict_types=1);

namespace App\Support\Playbooks;

use App\Enums\PlaybookKind;
use App\Models\Playbook;
use Illuminate\Validation\ValidationException;

/**
 * v1 debt routing: at most one active debt playbook per site-filter coverage set.
 * Empty site_ids means all sites. policy_ids / min_days_overdue do not split exclusivity.
 */
final class DebtPlaybookOverlap
{
    public static function assertCanActivate(Playbook $playbook): void
    {
        if ($playbook->kind !== PlaybookKind::DebtProcess) {
            return;
        }

        $mine = self::siteCoverage($playbook->enrolment_filters ?? []);

        $others = Playbook::query()
            ->where('kind', PlaybookKind::DebtProcess)
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->whereKeyNot($playbook->id)
            ->get(['id', 'name', 'enrolment_filters']);

        foreach ($others as $other) {
            if (self::coversOverlap($mine, self::siteCoverage($other->enrolment_filters ?? []))) {
                throw ValidationException::withMessages([
                    'status' => "Another active debt playbook [{$other->name}] already covers one or more of the same sites.",
                ]);
            }
        }
    }

    /**
     * null = all sites; otherwise the explicit site id list.
     *
     * @param  array<string, mixed>  $filters
     * @return list<int>|null
     */
    public static function siteCoverage(array $filters): ?array
    {
        $raw = $filters['site_ids'] ?? null;
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $ids = [];
        foreach ($raw as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        $ids = array_values(array_unique($ids));

        return $ids === [] ? null : $ids;
    }

    /**
     * @param  list<int>|null  $a
     * @param  list<int>|null  $b
     */
    public static function coversOverlap(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return true;
        }

        return array_intersect($a, $b) !== [];
    }
}
