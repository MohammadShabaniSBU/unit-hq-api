<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Support\Ai\Enums\ToolErrorCode;

final readonly class ToolError
{
    /**
     * @param  array{tool?: string, hint: string}|null  $recovery
     * @param  list<EntityRef>  $candidates
     * @param  array<string, mixed>|null  $detail
     */
    public function __construct(
        public ToolErrorCode $errorCode,
        public string $message,
        public ?array $recovery = null,
        public array $candidates = [],
        public ?array $detail = null,
    ) {}

    public static function siteUnresolved(string $message, array $candidates = []): self
    {
        return new self(
            ToolErrorCode::SiteUnresolved,
            $message,
            [
                'tool' => 'facility.find_sites',
                'hint' => 'call facility.find_sites with a city or postcode',
            ],
            $candidates,
        );
    }

    /**
     * @param  array{tool?: string, hint: string}|null  $recovery
     */
    public static function invalidArguments(string $message, ?array $recovery = null): self
    {
        return new self(ToolErrorCode::InvalidArguments, $message, $recovery);
    }

    /**
     * @param  array{tool?: string, hint: string}|null  $recovery
     */
    public static function notFound(string $message, ?array $recovery = null): self
    {
        return new self(ToolErrorCode::NotFound, $message, $recovery);
    }

    /**
     * @param  array{tool?: string, hint: string}|null  $recovery
     */
    public static function unavailable(string $message, ?array $recovery = null): self
    {
        return new self(ToolErrorCode::Unavailable, $message, $recovery);
    }

    /**
     * @param  array{tool?: string, hint: string}|null  $recovery
     */
    public static function unlicensedArgument(string $message, ?array $recovery = null): self
    {
        return new self(ToolErrorCode::UnlicensedArgument, $message, $recovery);
    }

    /**
     * @param  array{superseded: 'price'|'tax_rate', quoted: int, current: int|null}  $detail
     */
    public static function priceSuperseded(string $message, array $detail): self
    {
        return new self(
            ToolErrorCode::PriceSuperseded,
            $message,
            [
                'tool' => 'pricing.quote',
                'hint' => 'requote with pricing.quote; the catalogue has changed',
            ],
            [],
            $detail,
        );
    }

    /**
     * Recovery-oriented one-liner fed to the model. Never the developer message.
     */
    public function summary(): string
    {
        if ($this->recovery === null) {
            return $this->errorCode->value;
        }

        $hint = $this->recovery['hint'] ?? '';
        $tool = $this->recovery['tool'] ?? '';

        if ($hint !== '') {
            return $this->errorCode->value.': '.$hint;
        }

        return $tool !== ''
            ? $this->errorCode->value.': call '.$tool
            : $this->errorCode->value;
    }

    /**
     * Stable Recovery: marker appended to the model-facing tool message.
     */
    public function recoveryLine(): string
    {
        if ($this->recovery === null) {
            return '';
        }

        $hint = $this->recovery['hint'] ?? '';
        $tool = $this->recovery['tool'] ?? '';
        if ($tool !== '') {
            return $hint !== ''
                ? "Recovery: call {$tool} — {$hint}"
                : "Recovery: call {$tool}";
        }

        return $hint !== '' ? "Recovery: {$hint}" : '';
    }

    /**
     * @param  array{code: string, message: string, recovery?: array{tool?: string, hint: string}|null, candidates?: list<array{type: string, id: int, label: string, context?: string|null}>, detail?: array<string, mixed>|null}  $row
     */
    public static function fromArray(array $row): self
    {
        $recovery = null;
        if (isset($row['recovery']) && is_array($row['recovery'])) {
            $hint = isset($row['recovery']['hint']) ? (string) $row['recovery']['hint'] : '';
            $tool = isset($row['recovery']['tool']) ? (string) $row['recovery']['tool'] : '';
            if ($hint !== '' || $tool !== '') {
                $recovery = ['hint' => $hint];
                if ($tool !== '') {
                    $recovery['tool'] = $tool;
                }
            }
        }

        $candidates = [];
        foreach ($row['candidates'] ?? [] as $candidate) {
            if (is_array($candidate) && isset($candidate['type'], $candidate['id'], $candidate['label'])) {
                /** @var array{type: string, id: int, label: string, context?: string|null} $candidate */
                $candidates[] = EntityRef::fromArray($candidate);
            }
        }

        $detail = null;
        if (isset($row['detail']) && is_array($row['detail'])) {
            $detail = $row['detail'];
        }

        return new self(
            ToolErrorCode::from((string) $row['code']),
            (string) $row['message'],
            $recovery,
            $candidates,
            $detail,
        );
    }

    /**
     * @return array{code: string, message: string, recovery: array{tool?: string, hint: string}|null, candidates: list<array{type: string, id: int, label: string, context: string|null}>, detail: array<string, mixed>|null}
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
            'detail' => $this->detail,
        ];
    }
}
