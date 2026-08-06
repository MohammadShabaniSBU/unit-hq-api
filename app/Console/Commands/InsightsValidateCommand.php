<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InsightReportSource;
use App\Enums\InsightValidationStatus;
use App\Enums\LogChannel;
use App\Models\InsightReport;
use App\Support\Insights\ReportValidator;
use App\Support\RecordsActivity;
use Illuminate\Console\Command;

/**
 * Re-check every non-archived embedded report against its live provider.
 * Logs Tier-2 activity only when status transitions away from valid.
 */
class InsightsValidateCommand extends Command
{
    protected $signature = 'insights:validate';

    protected $description = 'Validate embedded insight reports against their analytics providers';

    public function handle(ReportValidator $validator): int
    {
        $reports = InsightReport::query()
            ->active()
            ->where('source', InsightReportSource::Embedded)
            ->with(['params', 'analyticsAccount'])
            ->orderBy('id')
            ->get();

        $checked = 0;
        $transitions = 0;

        foreach ($reports as $report) {
            $previous = $report->validation_status;
            $result = $validator->validate($report);

            $report->fill([
                'last_validated_at' => $result->validatedAt,
                'validation_status' => $result->status->value,
                'validation_detail' => $result->detail,
            ]);
            $report->save();

            $checked++;

            if ($previous === InsightValidationStatus::Valid
                && $result->status !== InsightValidationStatus::Valid
            ) {
                RecordsActivity::log(
                    LogChannel::Facility,
                    'insight.report.validation_failed',
                    $report,
                    [
                        'report_key' => $report->key,
                        'previous_status' => $previous->value,
                        'status' => $result->status->value,
                        'detail' => $result->detail,
                    ],
                    anonymous: true,
                );
                $transitions++;
            }
        }

        $this->info("insights:validate — checked {$checked} embedded report(s), {$transitions} transition(s) logged.");

        return self::SUCCESS;
    }
}
