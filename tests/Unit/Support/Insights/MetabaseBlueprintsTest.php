<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Insights;

use App\Support\Insights\NativeReports;
use App\Support\Insights\Provisioning\MetabaseBlueprints;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetabaseBlueprintsTest extends TestCase
{
    /** @var list<string> */
    private const PUBLIC_TABLES = [
        'charges',
        'payments',
        'units',
        'contracts',
        'contacts',
        'unit_occupancies',
        'unit_holds',
        'unit_classes',
        'sites',
        'deals',
        'offers',
        'reservations',
        'activity_log',
        'invoices',
        'allocations',
        'prices',
    ];

    #[Test]
    public function sql_is_bounded_to_analytics(): void
    {
        foreach ($this->allSql() as $sql) {
            $body = $this->stripComments($sql);
            $this->assertMatchesRegularExpression('/\banalytics\./', $body);
            $this->assertDoesNotMatchRegularExpression('/\bpublic\./i', $body);

            foreach (self::PUBLIC_TABLES as $table) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b(?:from|join)\s+'.$table.'\b/i',
                    $body,
                    "Blueprint SQL must not read public.{$table}",
                );
            }
        }
    }

    #[Test]
    public function monetary_aggregates_group_by_currency(): void
    {
        foreach ($this->allSql() as $sql) {
            $body = $this->stripComments($sql);
            if (! preg_match('/\bSUM\s*\(/i', $body) || ! preg_match('/\bcurrency\b/i', $body)) {
                continue;
            }
            $this->assertMatchesRegularExpression(
                '/GROUP BY[\s\S]*\bcurrency\b/i',
                $body,
                'Monetary SUM must GROUP BY currency',
            );
        }
    }

    #[Test]
    public function mirrors_and_blocked_are_native_keys(): void
    {
        foreach (MetabaseBlueprints::all() as $entry) {
            $mirrors = $entry['mirrors'];
            $this->assertTrue(
                $mirrors === null || NativeReports::has($mirrors),
                'mirrors must be a NativeReports key or null',
            );
        }

        foreach (MetabaseBlueprints::BLOCKED as $key => $reason) {
            $this->assertTrue(NativeReports::has($key), "BLOCKED key [{$key}] is not a NativeReports key");
            $this->assertNotSame('', $reason);
        }
    }

    #[Test]
    public function occupancy_sql_does_not_use_current_date(): void
    {
        $occupancy = MetabaseBlueprints::get('occupancy');
        $this->assertNotNull($occupancy);

        foreach ($occupancy['cards'] as $card) {
            $body = $this->stripComments($card['sql']);
            $this->assertDoesNotMatchRegularExpression(
                '/\bCURRENT_DATE\b/i',
                $body,
                'Occupancy cards must anchor on max(day), not CURRENT_DATE',
            );
        }
    }

    /**
     * @return list<string>
     */
    private function allSql(): array
    {
        $out = [];
        foreach (MetabaseBlueprints::all() as $entry) {
            foreach ($entry['cards'] as $card) {
                $out[] = $card['sql'];
            }
        }

        return $out;
    }

    private function stripComments(string $sql): string
    {
        $sql = preg_replace('/--.*$/m', '', $sql) ?? $sql;
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;

        return $sql;
    }
}
