<?php

declare(strict_types=1);

namespace App\Support\Ai\Summaries;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class SummaryContextResolver
{
    public function resolve(Model $subject, Employee $viewer): SummaryContext
    {
        $caps = config('ai.summaries.caps', []);
        $caps = is_array($caps) ? $caps : [];

        return match ($subject->getMorphClass()) {
            'contact' => new ContactSummaryContext(
                $subject instanceof Contact ? $subject : throw new InvalidArgumentException('Expected Contact'),
                $viewer,
                $caps,
            ),
            'deal' => new DealSummaryContext(
                $subject instanceof Deal ? $subject : throw new InvalidArgumentException('Expected Deal'),
                $viewer,
                $caps,
            ),
            default => throw new InvalidArgumentException(
                'Unsupported summarizable type: '.$subject->getMorphClass()
            ),
        };
    }
}
