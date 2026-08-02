<?php

declare(strict_types=1);

namespace App\Support\Reports;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pure query-class base. Every report inherits bounded-query assertions
 * via {@see self::runBounded()}.
 */
abstract class AbstractReport
{
    abstract public static function name(): string;

    abstract public function run(ReportFilters $filters): ReportResult;

    /**
     * Max SQL statements allowed for {@see run()} at seed scale.
     * Concrete reports may tighten; the base test asserts inheritance.
     */
    public function maxQueries(): int
    {
        return 20;
    }

    public function runBounded(ReportFilters $filters): ReportResult
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $result = $this->run($filters);
            $count = count(DB::getQueryLog());

            if ($count > $this->maxQueries()) {
                throw new RuntimeException(sprintf(
                    'Report [%s] exceeded bounded query budget: %d > %d.',
                    static::name(),
                    $count,
                    $this->maxQueries(),
                ));
            }

            return $result;
        } finally {
            DB::disableQueryLog();
        }
    }
}
