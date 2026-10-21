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
 * `members_count` is maintained under the row lock of `LeagueService::place`.
 *
 * @property int $id
 * @property int $league_week_id
 * @property int $tier
 * @property int $capacity
 * @property int $members_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LeagueWeek|null $week
 */
class LeagueGroup extends Model
{
    /** @use HasFactory<LeagueGroupFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['league_week_id', 'tier', 'capacity', 'members_count'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'league_week_id' => 'integer',
            'tier' => 'integer',
            'capacity' => 'integer',
            'members_count' => 'integer',
        ];
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
