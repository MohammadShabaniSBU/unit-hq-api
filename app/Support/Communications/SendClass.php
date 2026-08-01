<?php

declare(strict_types=1);

namespace App\Support\Communications;

enum SendClass: string
{
    case Transactional = 'transactional';
    case Marketing = 'marketing';
}
