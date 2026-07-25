<?php

declare(strict_types=1);

namespace App\Enums;

enum LayoutFieldType: string
{
    case Native = 'native';
    case Attribute = 'attribute';
}
