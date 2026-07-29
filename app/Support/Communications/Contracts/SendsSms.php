<?php

declare(strict_types=1);

namespace App\Support\Communications\Contracts;

use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\Results\SendResult;

interface SendsSms
{
    public function sendSms(SmsMessage $message): SendResult;
}
