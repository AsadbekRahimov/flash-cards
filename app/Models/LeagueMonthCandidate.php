<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Engagement\Enums\LeagueMonthCandidateStatus;
use Database\Factories\LeagueMonthCandidateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A candidate for a prize of a month (docs/13 §7.6): frozen at the close among the best eligible participants, settled by
 * the administrator (`winner` with a `place`, or `disqualified` with a reason). `signals` are the anti-abuse facts shown
 * at the review; nothing is decided by them.
 *
 * @property int $id
 * @property int $league_month_id
 * @property int $learner_id
 * @property int $rank
 * @property int $points
 * @property int $active_days
 * @property array<string, mixed> $signals
 * @property string $status
 * @property int|null $place
 * @property Carbon|null $disqualified_at
 * @property int|null $disqualified_by_user_id
 * @property string|null $disqualify_reason
 * @property Carbon|null $prize_delivered_at
 * @property int|null $prize_delivered_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Learner|null $learner
 * @property-read LeagueMonth|null $month
 */
class LeagueMonthCandidate extends Model
{
    /** @use HasFactory<LeagueMonthCandidateFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'league_month_id', 'learner_id', 'rank', 'points', 'active_days', 'signals', 'status', 'place',
        'disqualified_at', 'disqualified_by_user_id', 'disqualify_reason', 'prize_delivered_at', 'prize_delivered_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'league_month_id' => 'integer',
            'learner_id' => 'integer',
            'rank' => 'integer',
            'points' => 'integer',
            'active_days' => 'integer',
            'signals' => 'array',
            'place' => 'integer',
            'disqualified_at' => 'datetime',
            'disqualified_by_user_id' => 'integer',
            'prize_delivered_at' => 'datetime',
            'prize_delivered_by_user_id' => 'integer',
        ];
    }

    public function status(): LeagueMonthCandidateStatus
    {
        return LeagueMonthCandidateStatus::tryFrom($this->status) ?? LeagueMonthCandidateStatus::Candidate;
    }

    public function isDisqualified(): bool
    {
        return $this->status === LeagueMonthCandidateStatus::Disqualified->value;
    }

    public function isWinner(): bool
    {
        return $this->status === LeagueMonthCandidateStatus::Winner->value;
    }

    /** @return BelongsTo<LeagueMonth, $this> */
    public function month(): BelongsTo
    {
        return $this->belongsTo(LeagueMonth::class, 'league_month_id');
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
