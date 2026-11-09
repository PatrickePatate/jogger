<?php

declare(strict_types=1);

namespace App\Domain\Github\Enums;

enum DeliveryStatus: string
{
    case Received = 'received';       // stored, waiting for a worker
    case Processing = 'processing';   // claimed by a worker
    case Processed = 'processed';
    case Failed = 'failed';
    case Ignored = 'ignored';
}
