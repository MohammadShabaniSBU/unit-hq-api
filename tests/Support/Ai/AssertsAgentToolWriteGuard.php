<?php

declare(strict_types=1);

namespace Tests\Support\Ai;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

trait AssertsAgentToolWriteGuard
{
    /** @var list<string> */
    private const FORBIDDEN_TABLES = [
        'charges',
        'payments',
        'allocations',
        'contracts',
        'contract_items',
        'invoices',
        'access_grants',
        'access_suspensions',
        'offers',
        'reservations',
    ];

    protected function startWriteGuard(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
    }

    protected function assertNoForbiddenWrites(): void
    {
        $queries = DB::getQueryLog();
        Assert::assertNotEmpty($queries);

        foreach ($queries as $entry) {
            $sql = strtolower((string) ($entry['query'] ?? ''));
            foreach (self::FORBIDDEN_TABLES as $table) {
                $quoted = preg_quote($table, '/');
                if (preg_match('/\b(insert\s+into|update|delete\s+from)\s+["`]?'.$quoted.'["`]?\b/', $sql) === 1) {
                    Assert::fail("Forbidden write against {$table}: {$sql}");
                }
            }
        }
    }
}
