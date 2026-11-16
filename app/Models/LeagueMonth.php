<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Engagement\Enums\LeagueMonthStatus;
use Database\Factories\LeagueMonthFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One month of the rating with prizes (docs/13 §7.6): the calendar month of `config('leagues.timezone')`.
 *
 * @property int $id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $status
 * @property array<int, mixed>|null $prizes `[{place, ru, uz}, …]` as written by MonthlyRatingReviewService::setPrizes; read through MonthlyRatingService::prizes()
 * @property int $participants_count
 * @property Carbon|null $refreshed_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $confirmed_at
 * @property int|null $confirmed_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LeagueMonth extends Model
{
    /** @use HasFactory<LeagueMonthFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['starts_on', 'ends_on', 'status', 'prizes', 'participants_count', 'refreshed_at', 'closed_at', 'confirmed_at', 'confirmed_by_user_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'prizes' => 'array',
            'participants_count' => 'integer',
            'refreshed_at' => 'datetime',
            'closed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'confirmed_by_user_id' => 'integer',
        ];
    }

    public function status(): LeagueMonthStatus
    {
        return LeagueMonthStatus::tryFrom($this->status) ?? LeagueMonthStatus::Open;
    }

    public function isOpen(): bool
    {
        return $this->status === LeagueMonthStatus::Open->value;
    }

    public function isConfirmed(): bool
    {
        return $this->status === LeagueMonthStatus::Confirmed->value;
    }

    /** The close has happened: the scores are final (`pending_review` or `confirmed`). */
    public function isClosed(): bool
    {
        return in_array($this->status, [LeagueMonthStatus::PendingReview->value, LeagueMonthStatus::Confirmed->value], true);
    }

    /** @return HasMany<LeagueMonthScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(LeagueMonthScore::class);
    }

    /** @return HasMany<LeagueMonthCandidate, $this> */
    public function candidates(): HasMany
    {
        return $this->hasMany(LeagueMonthCandidate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }
}
