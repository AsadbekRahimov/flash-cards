<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Engagement\Enums\LeagueWeekStatus;
use Database\Factories\LeagueWeekFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One league week (docs/13 §1.5): Monday–Sunday in `config('leagues.timezone')`.
 *
 * @property int $id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $status
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LeagueWeek extends Model
{
    /** @use HasFactory<LeagueWeekFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['starts_on', 'ends_on', 'status', 'closed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === LeagueWeekStatus::Closed->value;
    }

    /** @return HasMany<LeagueGroup, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(LeagueGroup::class);
    }

    /** @return HasMany<LeagueMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(LeagueMember::class);
    }
}
