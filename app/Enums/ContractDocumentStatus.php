<?php

declare(strict_types=1);

namespace App\Enums;

enum ContractDocumentStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Signed = 'signed';
    case Superseded = 'superseded';
}
