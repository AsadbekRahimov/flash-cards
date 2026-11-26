<?php

declare(strict_types=1);

namespace App\Domain\Cup\Enums;

/** `cups.status` (docs/33 §3.1): draft → scheduled → running → finished; any unfinished one → canceled. */
enum CupStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Running = 'running';
    case Finished = 'finished';
    case Canceled = 'canceled';

    /** Holds `active_group_id`: the one cup of its chat. */
    public function isOpen(): bool
    {
        return $this === self::Draft || $this === self::Scheduled || $this === self::Running;
    }
}
