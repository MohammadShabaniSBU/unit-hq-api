<?php

declare(strict_types=1);

namespace App\Support\Communications\Contracts;

use App\Support\Communications\Messages\WhatsAppSessionMessage;
use App\Support\Communications\Messages\WhatsAppTemplateMessage;
use App\Support\Communications\Results\SendResult;

interface SendsWhatsApp
{
    public function sendSession(WhatsAppSessionMessage $message): SendResult;

    public function sendTemplate(WhatsAppTemplateMessage $message): SendResult;
}
