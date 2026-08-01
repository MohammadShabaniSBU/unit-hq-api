<?php

declare(strict_types=1);

namespace App\Support\Playbooks;

use App\Enums\PlaybookStepAction;

/**
 * Kind-specific enrolment / exit / action surface for playbooks.
 */
interface PlaybookKind
{
    /**
     * Trigger node config (type + config payload used by the compiler).
     * Enrolment filters are compiled into trigger conditions (whitelisted fields only).
     *
     * @param  array<string, mixed>  $filters
     * @return array{type: string, label: string, config: array<string, mixed>}
     */
    public function trigger(array $filters): array;

    /**
     * Run-guard stay tree (evaluator polarity: must hold; fail → cancel).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function guard(array $filters): array;

    /**
     * @return list<PlaybookStepAction>
     */
    public function allowedActions(): array;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function validateFilters(array $filters): void;

    /** Vocabulary + panel links: 'delinquency' | 'deal'. */
    public function subjectDescriptor(): string;
}
