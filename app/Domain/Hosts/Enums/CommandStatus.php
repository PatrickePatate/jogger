<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Enums;

enum CommandStatus: string
{
    case Pending = 'pending';   // waiting for the agent to fetch it
    case Sent = 'sent';         // handed to the agent, not acknowledged yet
    case Acked = 'acked';       // agent reported success
    case Failed = 'failed';     // agent reported an error, or delivery gave up
}
