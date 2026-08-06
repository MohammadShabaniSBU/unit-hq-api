<?php

declare(strict_types=1);

namespace App\Enums;

enum InsightResourceKind: string
{
    case Dashboard = 'dashboard';
    case Question = 'question';
}
