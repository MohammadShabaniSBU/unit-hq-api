<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Site;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Time\RelativeDatePhrase;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;

final class CalendarResolveTool implements AgentTool
{
    public function key(): string
    {
        return 'calendar.resolve';
    }

    public function description(): string
    {
        return 'Resolve a customer relative-date phrase (next Monday, in 2 weeks, 15 January) to an ISO civil date. Pass their exact words. Never compute a date yourself.';
    }

    public function schema(): array
    {
        return [
            'phrase' => [
                'type' => 'string',
                'required' => true,
                'description' => "The customer's words, verbatim, at most 60 characters",
            ],
            'site_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Site whose timezone defines today. Omit to use the app timezone.',
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

    public function retainInSummary(): bool
    {
        return true;
    }

    public function contactScopedArgumentKeys(): array
    {
        return [];
    }

    public function entityArguments(): array
    {
        return [
            'site_id' => EntityType::Site,
        ];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $phrase = trim((string) ($arguments['phrase'] ?? ''));
        if ($phrase === '') {
            return ToolResult::fail($this->unresolved());
        }
        if (mb_strlen($phrase) > 60) {
            return ToolResult::fail($this->unresolved());
        }

        $siteId = isset($arguments['site_id']) ? (int) $arguments['site_id'] : $principal->siteId;
        $site = $siteId !== null && $siteId > 0 ? Site::query()->find($siteId) : null;
        $timezone = $site !== null ? $site->timezone : (string) config('app.timezone', 'UTC');
        $today = $site !== null
            ? SiteClock::today($site)
            : CarbonImmutable::now($timezone)->startOfDay();

        $resolved = RelativeDatePhrase::resolve($phrase, $today);
        if ($resolved === null) {
            return ToolResult::fail($this->unresolved());
        }

        $iso = $resolved->toDateString();
        $locale = $this->localeKey($principal->locale);
        $weekdayLocal = $resolved->locale($locale)->isoFormat('dddd');
        $display = '"'.$phrase.'" → '.$iso.' ('.$weekdayLocal.')';
        $facts = (new FactBag)->date($iso);

        return ToolResult::ok(
            [
                'iso' => $iso,
                'weekday' => $resolved->format('l'),
                'phrase' => $phrase,
                'timezone' => $timezone,
            ],
            $display,
            $facts,
        );
    }

    private function unresolved(): ToolError
    {
        return ToolError::invalidArguments(
            'Could not resolve that date phrase.',
            ['hint' => 'ask the customer for the exact date'],
        );
    }

    private function localeKey(string $locale): string
    {
        $base = strtolower(str_replace('_', '-', $locale));
        $base = explode('-', $base)[0];

        return in_array($base, ['en', 'es', 'fr'], true) ? $base : 'en';
    }
}
