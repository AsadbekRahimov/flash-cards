<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The rated duel of a participant on a day (docs/33 §2.9): UNIQUE `(participant_id, day)` is the quota "one rated duel a day". Two rows
 * are written by the acceptance of a rated duel in its transaction and deleted when the duel is void. Written by the query builder of
 * {@see \App\Domain\Cup\Services\CupDuelService} only; the model is for reading.
 *
 * @property int $id
 * @property int $cup_id
 * @property int $participant_id
 * @property int $day
 * @property int $cup_duel_id
 * @property CarbonInterface|null $created_at
 */
class CupDuelSlot extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['day' => 'integer'];
    }
}
