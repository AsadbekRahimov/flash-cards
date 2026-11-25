<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Cup\Enums\CupCancelReason;
use App\Domain\Cup\Enums\CupStatus;
use Carbon\CarbonInterface;
use Database\Factories\CupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A friends' cup of a Telegram group (docs/33 §2.2). Plain data: the rules live in `App\Domain\Cup\Services`.
 *
 * `seed_secret` (server only, never in a resource) seeds the plan of the words, the snapshots of the days and the order of every
 * participant; `word_plan` is `{"1": [word ids], …}`, fixed at the start.
 *
 * @property int $id
 * @property int $telegram_group_id
 * @property int|null $active_group_id
 * @property string $public_code
 * @property CupStatus $status
 * @property CupCancelReason|null $cancel_reason
 * @property int $course_id
 * @property string $level
 * @property string $timezone
 * @property int $days
 * @property int $counted_days
 * @property int $round_tasks
 * @property int $duel_tasks
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property bool $summaries_enabled
 * @property int $summary_minute
 * @property string $locale
 * @property string $seed_secret
 * @property array<int|string, list<int>>|null $word_plan
 * @property int $proposed_by_telegram_user_id
 * @property int|null $confirmed_by_telegram_user_id
 * @property CarbonInterface|null $confirmed_at
 * @property int|null $card_chat_id
 * @property int|null $card_message_id
 * @property int|null $card_thread_id
 * @property string|null $card_text_hash
 * @property CarbonInterface|null $card_rendered_at
 * @property string|null $summary_scores_hash
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface|null $canceled_at
 * @property array<string, mixed> $meta
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read TelegramGroup $telegramGroup
 * @property-read Course $course
 */
class Cup extends Model
{
    /** @use HasFactory<CupFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['seed_secret', 'word_plan'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CupStatus::class,
            'cancel_reason' => CupCancelReason::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'card_rendered_at' => 'datetime',
            'finished_at' => 'datetime',
            'canceled_at' => 'datetime',
            'summaries_enabled' => 'boolean',
            'word_plan' => 'array',
            'meta' => 'array',
            'active_group_id' => 'integer',
            'days' => 'integer',
            'counted_days' => 'integer',
            'round_tasks' => 'integer',
            'duel_tasks' => 'integer',
            'summary_minute' => 'integer',
            'proposed_by_telegram_user_id' => 'integer',
            'confirmed_by_telegram_user_id' => 'integer',
            'card_chat_id' => 'integer',
            'card_message_id' => 'integer',
            'card_thread_id' => 'integer',
        ];
    }

    /** @return BelongsTo<TelegramGroup, $this> */
    public function telegramGroup(): BelongsTo
    {
        return $this->belongsTo(TelegramGroup::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<CupParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(CupParticipant::class);
    }

    /** @return HasMany<CupDay, $this> */
    public function cupDays(): HasMany
    {
        return $this->hasMany(CupDay::class);
    }
}
