<?php

declare(strict_types=1);

namespace App\Domain\Cup\Enums;

/** `cup_messages.status` (docs/33 §6.4): pending → sending → sent / skipped / unknown / failed. */
enum CupMessageStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Unknown = 'unknown';
    case Failed = 'failed';
}
