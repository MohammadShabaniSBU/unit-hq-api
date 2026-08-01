<?php

declare(strict_types=1);

namespace App\Support\Playbooks;

use App\Enums\PlaybookKind as PlaybookKindEnum;
use App\Support\Playbooks\Kinds\DebtProcess;
use App\Support\Playbooks\Kinds\LeadChase;
use InvalidArgumentException;

final class PlaybookKindRegistry
{
    public static function for(PlaybookKindEnum|string $kind): PlaybookKind
    {
        $value = $kind instanceof PlaybookKindEnum ? $kind : PlaybookKindEnum::tryFrom($kind);

        return match ($value) {
            PlaybookKindEnum::DebtProcess => new DebtProcess,
            PlaybookKindEnum::LeadChase => new LeadChase,
            default => throw new InvalidArgumentException(
                'Unknown playbook kind: '.($kind instanceof PlaybookKindEnum ? $kind->value : (string) $kind),
            ),
        };
    }
}
