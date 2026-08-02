<?php

declare(strict_types=1);

namespace App\Enums;

enum PlaybookStepAction: string
{
    case SendEmail = 'send_email';
    case SendSms = 'send_sms';
    case SendWhatsappTemplate = 'send_whatsapp_template';
    case CreateTask = 'create_task';
    case RecordNotice = 'record_notice';
}
