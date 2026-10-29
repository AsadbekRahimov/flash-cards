<?php

declare(strict_types=1);

namespace App\Domain\Practice\Enums;

/** The audio of a dictation version (`practice_item_versions.audio_status`, docs/16 §2.3, §10.1). */
enum AudioStatus: string
{
    /** Not a dictation: nothing to voice. */
    case None = 'none';
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
