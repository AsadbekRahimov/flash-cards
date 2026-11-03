<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

/** `game_runs.finish_reason` (docs/18 §12.2.1): `completed` (the last element is answered), `failed` (the flasks are out), `expired` (the TTL), `abandoned` (the learner left). */
enum RunFinishReason: string
{
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
    case Abandoned = 'abandoned';

    /** Only a run that was played to its end pays: the rewards, the progress of the map and the day of the learner (docs/18 §12.2.1). */
    public function pays(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
