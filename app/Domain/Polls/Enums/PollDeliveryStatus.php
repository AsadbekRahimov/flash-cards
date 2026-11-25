<?php

declare(strict_types=1);

namespace App\Domain\Polls\Enums;

/**
 * `poll_deliveries.status` (docs/35 §1.5 "Рассылка"): `pending` - waits for its batch; `sending` - captured by a worker (a single
 * conditional UPDATE from `pending`), the message may be on its way; then one of the final ones: `sent`, `skipped` (the learner became
 * unreachable before the send, or the poll was cancelled), `forbidden` (403 / chat not found), `failed` (any other refusal) and
 * `unknown` (left in `sending` by a crashed job and swept after 15 minutes - never sent again: a duplicate is worse than a miss).
 * A 429 puts the row back to `pending`.
 */
enum PollDeliveryStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Forbidden = 'forbidden';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
