<?php

declare(strict_types=1);

namespace App\Support\Templates;

enum TokenType: string
{
    case Text = 'text';
    case Money = 'money';
    case Date = 'date';
    case Url = 'url';
    case Number = 'number';
}
