<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LeagueGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A group of one tier inside a league week (docs/13 §1.5), at most `capacity` members.
 * `members_count` is maintained under the row lock of `LeagueService::place`. `closed_at` is
 * stamped once, when the group's final points / ranks / results were written (`LeagueService::closeGroup`
 * — by the week close or by a member's placement into the next week); from then on the standings are
 * read from `league_members.final_*` and nobody is seated in the group any more.
 *
 * @property int $id
 * @property int $league_week_id
 * @property int $tier
 * @property int $capacity
 * @property int $members_count
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LeagueWeek|null $week
 */
class LeagueGroup extends Model
{
    /** @use HasFactory<LeagueGroupFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['league_week_id', 'tier', 'capacity', 'members_count', 'closed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'league_week_id' => 'integer',
            'tier' => 'integer',
            'capacity' => 'integer',
            'members_count' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    /** The final results of this group were written (the week may still be `closing`). */
    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /** @return BelongsTo<LeagueWeek, $this> */
    public function week(): BelongsTo
    {
        return $this->belongsTo(LeagueWeek::class, 'league_week_id');
    }

    /** @return HasMany<LeagueMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(LeagueMember::class);
    }
}
