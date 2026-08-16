<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

final class CannedReply
{
    public const Handoff = 'I am connecting you with a teammate who can help with this.';

    public const Budget = 'I have reached the limit for this conversation and am handing you to a teammate.';

    public const Error = 'Something went wrong. I am connecting you with a teammate.';

    public const Blocked = 'I need to hand this to a teammate.';
}
