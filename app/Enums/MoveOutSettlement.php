<?php

declare(strict_types=1);

namespace App\Enums;

enum MoveOutSettlement: string
{
    case None = 'none';
    case Daily = 'daily';
    case NoticeBased = 'notice_based';
}
