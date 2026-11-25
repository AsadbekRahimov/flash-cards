<?php

declare(strict_types=1);

namespace App\Domain\Polls\Enums;

/**
 * `polls.status` (docs/35 §1.4): `draft` → `sending` (launched, the deliveries are inserted and going out) → `sent` (no delivery is
 * pending or sending any more) → `closed` (by hand or at `closes_at`; `results_snapshot` written). `cancelled` - the broadcast was
 * stopped from `draft` or `sending`. Votes are taken while `sending` or `sent` and before `closes_at`.
 */
enum PollStatus: string
{
    case Draft = 'draft';
    case Sending = 'sending';
    case Sent = 'sent';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
