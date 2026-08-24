<?php

declare(strict_types=1);

namespace App\Support\Leasing;

use App\Models\AiAgent;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;

/**
 * Causer for leasing writes. Distinct factories so later provenance (S24-01)
 * can tell public-link accept from system sweeps without reopening signatures.
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
}
