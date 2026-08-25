<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Support\Ai\Enums\ToolErrorCode;

final readonly class ToolError
{
    /**
     * @param  array{tool: string, hint: string}|null  $recovery
     * @param  list<EntityRef>  $candidates
     */
    public function __construct(
        public ToolErrorCode $errorCode,
        public string $message,
        public ?array $recovery = null,
        public array $candidates = [],
    ) {}

    public static function siteUnresolved(string $message): self
    {
        return new self(
            ToolErrorCode::SiteUnresolved,
            $message,
            [
                'tool' => 'facility.find_sites',
                'hint' => 'call facility.find_sites with a city or postcode',
            ],
        );
    }

    public static function invalidArguments(string $message): self
    {
        return new self(ToolErrorCode::InvalidArguments, $message);
    }

    public static function notFound(string $message): self
    {
        return new self(ToolErrorCode::NotFound, $message);
    }

    public static function unavailable(string $message, ?array $recovery = null): self
    {
        return new self(ToolErrorCode::Unavailable, $message, $recovery);
    }

    public static function unlicensedArgument(string $message, ?array $recovery = null): self
    {
        return new self(ToolErrorCode::UnlicensedArgument, $message, $recovery);
    }

    /**
     * Recovery-oriented one-liner fed to the model. Never the developer message.
     */
    public function summary(): string
    {
        if ($this->recovery === null) {
            return $this->errorCode->value;
        }

        $hint = $this->recovery['hint'];

        return $hint !== ''
            ? $this->errorCode->value.': '.$hint
            : $this->errorCode->value.': call '.$this->recovery['tool'];
    }

    /**
     * @param  array{code: string, message: string, recovery?: array{tool: string, hint: string}|null, candidates?: list<array{type: string, id: int, label: string, context?: string|null}>}  $row
     */
    public static function fromArray(array $row): self
    {
        $recovery = null;
        if (isset($row['recovery']) && is_array($row['recovery']) && isset($row['recovery']['tool'], $row['recovery']['hint'])) {
            $recovery = [
                'tool' => (string) $row['recovery']['tool'],
                'hint' => (string) $row['recovery']['hint'],
            ];
        }

        $candidates = [];
        foreach ($row['candidates'] ?? [] as $candidate) {
            if (is_array($candidate) && isset($candidate['type'], $candidate['id'], $candidate['label'])) {
                /** @var array{type: string, id: int, label: string, context?: string|null} $candidate */
                $candidates[] = EntityRef::fromArray($candidate);
            }
        }

        return new self(
            ToolErrorCode::from((string) $row['code']),
            (string) $row['message'],
            $recovery,
            $candidates,
        );
    }

    /**
     * @return array{code: string, message: string, recovery: array{tool: string, hint: string}|null, candidates: list<array{type: string, id: int, label: string, context: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->errorCode->value,
            'message' => $this->message,
            'recovery' => $this->recovery,
            'candidates' => array_map(
                static fn (EntityRef $ref): array => $ref->toArray(),
                $this->candidates,
            ),
        ];
    }
}
