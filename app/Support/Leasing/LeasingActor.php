<?php

declare(strict_types=1);

namespace App\Support\Leasing;

use App\Enums\PipelineSource;
use App\Models\AiAgent;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Causer for leasing writes. Provenance is derived from the actor — never
 * passed as a caller argument — so a write cannot lie about its source.
 *
 * Mapping: employee → operator, agent → ai_agent, public_link → public_link,
 * system → automation (no offer/reservation create uses system() today).
 */
final readonly class LeasingActor
{
    private function __construct(
        public ?Employee $employee,
        public ?AiAgent $agent,
        public string $origin,
    ) {}

    public static function employee(Employee $employee): self
    {
        return new self($employee, null, 'employee');
    }

    public static function agent(AiAgent $agent): self
    {
        return new self(null, $agent, 'agent');
    }

    public static function system(): self
    {
        return new self(null, null, 'system');
    }

    public static function publicLink(): self
    {
        return new self(null, null, 'public_link');
    }

    public function causer(): ?Model
    {
        return $this->employee ?? $this->agent;
    }

    public function employeeId(): ?int
    {
        return $this->employee?->id;
    }

    public function pipelineSource(): PipelineSource
    {
        return match ($this->origin) {
            'employee' => PipelineSource::Operator,
            'agent' => PipelineSource::AiAgent,
            'public_link' => PipelineSource::PublicLink,
            'system' => PipelineSource::Automation,
            default => throw new LogicException("Unknown leasing actor origin [{$this->origin}]."),
        };
    }

    public function aiAgentId(): ?int
    {
        return $this->agent?->id;
    }
}
