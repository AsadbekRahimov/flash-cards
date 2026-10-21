<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Engagement\Enums\LeagueResult;
use Database\Factories\LeagueMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A learner's place in one group of a league week (docs/13 §1.5; unique per week). `final_*`
 * and `result` stay NULL until `LeagueService::closeWeek` writes them once.
 *
 * @property int $id
 * @property int $league_group_id
 * @property int $league_week_id
 * @property int $learner_id
 * @property int $tier_before
 * @property int|null $final_points
 * @property int|null $final_rank
 * @property string|null $result
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LeagueGroup|null $group
 * @property-read LeagueWeek|null $week
 * @property-read Learner|null $learner
 */
class LeagueMember extends Model
{
    /** @use HasFactory<LeagueMemberFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['league_group_id', 'league_week_id', 'learner_id', 'tier_before', 'final_points', 'final_rank', 'result'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'league_group_id' => 'integer',
            'league_week_id' => 'integer',
            'learner_id' => 'integer',
            'tier_before' => 'integer',
            'final_points' => 'integer',
            'final_rank' => 'integer',
        ];
    }

    public function result(): ?LeagueResult
    {
        return $this->result === null ? null : LeagueResult::tryFrom($this->result);
    }

    /** The tier the result sends the learner to next week (bounded by the caller to the configured tiers). */
    public function tierAfter(): int
    {
        return $this->tier_before + ($this->result()?->tierDelta() ?? 0);
    }

    /** @return BelongsTo<LeagueGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(LeagueGroup::class, 'league_group_id');
    }

    /** @return BelongsTo<LeagueWeek, $this> */
    public function week(): BelongsTo
    {
        return $this->belongsTo(LeagueWeek::class, 'league_week_id');
    }

    /** The learner (null while soft-deleted). @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
