<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The points of a participant on a day of a cup (docs/33 §2.7): the round (0-20) and the duel (0-5, stage 2). Written by
 * `CupScoreBook` only, under the lock of the participant.
 *
 * @property int $id
 * @property int $cup_id
 * @property int $participant_id
 * @property int $day
 * @property int $round_points
 * @property int $duel_points
 * @property CarbonInterface|null $updated_at
 */
class CupDayScore extends Model
{
    public const CREATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'day' => 'integer',
            'round_points' => 'integer',
            'duel_points' => 'integer',
        ];
    }
}
