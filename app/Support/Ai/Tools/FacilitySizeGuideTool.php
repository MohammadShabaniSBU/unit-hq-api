<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Enums\SizeGuideMetric;
use App\Models\SizeGuide;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\ForbiddenClaimKey;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Facility\SizeGuideResolver;

final class FacilitySizeGuideTool implements AgentTool
{
    public function key(): string
    {
        return 'facility.size_guide';
    }

    public function description(): string
    {
        return 'Look up the operator size guide for a quantity of goods (standard boxes, rooms, or a vehicle). Returns matching bands with a disclaimer. Never invent how much fits in a unit; ask what they are storing and call this tool.';
    }

    public function schema(): array
    {
        return [
            'metric' => [
                'type' => 'string',
                'required' => true,
                'enum' => array_map(
                    static fn (SizeGuideMetric $metric): string => $metric->value,
                    SizeGuideMetric::cases(),
                ),
                'description' => 'What the customer is counting: standard_boxes, room_equivalent, or vehicle',
            ],
            'quantity' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'How many boxes, rooms, or vehicles. Omit to list every band for the metric.',
            ],
            'site_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Limit resolution to one site plus company defaults',
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
        return false;
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
        $metric = SizeGuideMetric::from((string) $arguments['metric']);
        $quantity = isset($arguments['quantity']) ? (int) $arguments['quantity'] : null;
        $siteId = isset($arguments['site_id']) ? (int) $arguments['site_id'] : $principal->siteId;

        $bands = app(SizeGuideResolver::class)->resolve($metric, $quantity, $siteId);
        if ($bands->isEmpty()) {
            return ToolResult::notFound(
                'No size-guide band matched that metric and quantity. Ask what they are storing.',
            );
        }

        $disclaimer = SizeGuideResolver::DISCLAIMER;
        $facts = new FactBag;
        $entities = [];
        $payload = [];
        $lines = [];
        $seen = [];

        foreach ($bands as $band) {
            $row = $this->bandPayload($band, $disclaimer);
            $payload[] = $row;
            $lines[] = $this->bandLine($band);

            // Dimensional labels like "12 m²" emit DraftToken::Number; identifier()
            // would not license them. number() on every size and quantity is the
            // licence grounding needs for a "12–16 m²" recommendation.
            if ($band->min_quantity !== null) {
                $facts->number($band->min_quantity);
            }
            if ($band->max_quantity !== null) {
                $facts->number($band->max_quantity);
            }
            if ($band->min_size !== null) {
                $facts->number($this->trimSize($band->min_size));
            }
            if ($band->max_size !== null) {
                $facts->number($this->trimSize($band->max_size));
            }

            $guideKey = 'size_guide:'.$band->id;
            if (! isset($seen[$guideKey])) {
                $seen[$guideKey] = true;
                $entities[] = EntityRef::sizeGuide($band);
            }

            if ($band->site !== null) {
                $siteKey = 'site:'.$band->site->id;
                if (! isset($seen[$siteKey])) {
                    $seen[$siteKey] = true;
                    $entities[] = EntityRef::site($band->site);
                }
            }

            if ($band->unitClass !== null) {
                $classKey = 'unit_class:'.$band->unitClass->id;
                if (! isset($seen[$classKey])) {
                    $seen[$classKey] = true;
                    $entities[] = EntityRef::unitClass($band->unitClass, $band->site);
                }
            }
        }

        $display = implode(' ', $lines).' '.$disclaimer;
        $facts->absorb($display);

        return ToolResult::ok(
            [
                'disclaimer' => $disclaimer,
                'bands' => $payload,
            ],
            $display,
            $facts,
            licensedClaims: [ForbiddenClaimKey::CapacityGuidance],
            entities: $entities,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function bandPayload(SizeGuide $band, string $disclaimer): array
    {
        $row = [
            'id' => $band->id,
            'metric' => $band->metric->value,
            'min_quantity' => $band->min_quantity,
            'max_quantity' => $band->max_quantity,
            'min_size' => $band->min_size !== null ? $this->trimSize($band->min_size) : null,
            'max_size' => $band->max_size !== null ? $this->trimSize($band->max_size) : null,
            'notes' => $band->notes,
            'disclaimer' => $disclaimer,
        ];
        if ($band->site_id !== null) {
            $row['site_id'] = $band->site_id;
            $row['site_name'] = $band->site?->name;
        }
        if ($band->unit_class_id !== null) {
            $row['unit_class_id'] = $band->unit_class_id;
            $row['unit_class_label'] = $band->unitClass?->label;
        }

        return $row;
    }

    private function bandLine(SizeGuide $band): string
    {
        $qty = $this->quantityPhrase($band);
        $metric = $this->metricPhrase($band->metric);
        $target = $this->sizePhrase($band);
        $notes = $band->notes !== null && $band->notes !== '' ? ' '.$band->notes : '';

        return "For {$qty} {$metric}, {$target} should work well.{$notes}";
    }

    private function quantityPhrase(SizeGuide $band): string
    {
        $min = $band->min_quantity;
        $max = $band->max_quantity;
        if ($min !== null && $max !== null && $min === $max) {
            return (string) $min;
        }
        if ($min !== null && $max !== null) {
            return "{$min}–{$max}";
        }
        if ($min !== null) {
            return "{$min}+";
        }
        if ($max !== null) {
            return "up to {$max}";
        }

        return 'those';
    }

    private function metricPhrase(SizeGuideMetric $metric): string
    {
        return match ($metric) {
            SizeGuideMetric::StandardBoxes => 'standard boxes',
            SizeGuideMetric::RoomEquivalent => 'rooms',
            SizeGuideMetric::Vehicle => 'vehicle',
        };
    }

    private function sizePhrase(SizeGuide $band): string
    {
        if ($band->unitClass !== null) {
            $label = $band->unitClass->label;
            $site = $band->site?->name;
            if ($site !== null) {
                return "{$label} at {$site}";
            }

            return $label;
        }

        $min = $band->min_size !== null ? $this->trimSize($band->min_size) : null;
        $max = $band->max_size !== null ? $this->trimSize($band->max_size) : null;
        if ($min !== null && $max !== null && $min === $max) {
            return "a unit around {$min} m²";
        }
        if ($min !== null && $max !== null) {
            return "a unit around {$min}–{$max} m²";
        }
        if ($min !== null) {
            return "a unit of at least {$min} m²";
        }
        if ($max !== null) {
            return "a unit up to {$max} m²";
        }

        return 'a matching unit';
    }

    private function trimSize(string $size): string
    {
        $trimmed = rtrim(rtrim($size, '0'), '.');

        return $trimmed !== '' ? $trimmed : '0';
    }
}
