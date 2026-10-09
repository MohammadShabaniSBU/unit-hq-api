<?php

declare(strict_types=1);

namespace App\Support\Communications\Exceptions;

use App\Models\TemplateFamily;
use RuntimeException;

final class TemplateNotPublished extends RuntimeException
{
    public static function forFamily(TemplateFamily $family): self
    {
        return new self("Template family [{$family->id}] has no published version.");
    }
}
