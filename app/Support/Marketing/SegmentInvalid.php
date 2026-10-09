<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A stored or submitted filter tree names a field the schema no longer accepts.
 */
final class SegmentInvalid extends RuntimeException
{
    public function __construct(public readonly string $field, string $detail)
    {
        parent::__construct($detail);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'segment.invalid',
            'errors' => [
                'filter' => [$this->getMessage()],
            ],
            'data' => [
                'field' => $this->field,
            ],
        ], 422);
    }

    public static function fromValidation(ValidationException $exception): self
    {
        $messages = $exception->errors()['filter'] ?? [];
        $detail = (string) ($messages[0] ?? 'Invalid segment filter.');
        $field = 'filter';

        if (preg_match('/\[([^\]]+)\]/', $detail, $matches) === 1) {
            $field = $matches[1];
        }

        return new self($field, $detail);
    }
}
