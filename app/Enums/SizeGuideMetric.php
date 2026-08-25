<?php

declare(strict_types=1);

namespace App\Enums;

enum SizeGuideMetric: string
{
    case StandardBoxes = 'standard_boxes';
    case RoomEquivalent = 'room_equivalent';
    case Vehicle = 'vehicle';
}
