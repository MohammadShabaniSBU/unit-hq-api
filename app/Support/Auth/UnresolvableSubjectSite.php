<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Thrown when SubjectSite has no arm for the model class — fail closed, never null.
 */
final class UnresolvableSubjectSite extends RuntimeException
{
    public function __construct(Model $subject)
    {
        parent::__construct(sprintf(
            'No SubjectSite resolution for %s',
            $subject::class,
        ));
    }
}
