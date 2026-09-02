<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

/**
 * What the agent does when SiteClock::withinWindow() is false.
 *
 * On email / SMS / WhatsApp, Inbox means skip the turn and wait — a human
 * will pick the thread up later. On voice there is no inbox a caller can
 * wait in: Inbox means take a message (cold-transfer to voicemail).
 * Answer still runs the agent; any transfer remaps to voicemail.
 */
enum OutsideHoursPolicy: string
{
    case Inbox = 'inbox';
    case Answer = 'answer';
}
