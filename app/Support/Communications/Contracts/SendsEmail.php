<?php

declare(strict_types=1);

namespace App\Support\Communications\Contracts;

use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\Results\SendResult;

interface SendsEmail
{
    public function sendEmail(EmailMessage $message): SendResult;
}
