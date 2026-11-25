<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Polls\Enums\PollDeliveryStatus;
use Database\Factories\PollDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One recipient of a poll (docs/35 §1.4): inserted at launch, sent at most once, then answered by the buttons of its `token`. A test
 * send to an administrator has `is_test = true` and no learner; it never counts in the results. Goes away with its learner.
 *
 * @property int $id
 * @property int $poll_id
 * @property int|null $learner_id
 * @property int $telegram_user_id
 * @property bool $is_test
 * @property string $token
 * @property string $locale
 * @property PollDeliveryStatus $status
 * @property int|null $message_id
 * @property Carbon|null $sent_at
 * @property string|null $error_code
 * @property list<int>|null $draft ticked, not yet submitted poll_options ids (multiple answers)
 * @property Carbon|null $answered_at
 * @property int $changed_count
 */
class PollDelivery extends Model
{
    /** @use HasFactory<PollDeliveryFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'poll_id', 'learner_id', 'telegram_user_id', 'is_test', 'token', 'locale', 'status', 'message_id', 'sent_at', 'error_code',
        'draft', 'answered_at', 'changed_count',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'is_test' => false,
        'changed_count' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'poll_id' => 'integer',
            'learner_id' => 'integer',
            'telegram_user_id' => 'integer',
            'is_test' => 'boolean',
            'status' => PollDeliveryStatus::class,
            'message_id' => 'integer',
            'sent_at' => 'datetime',
            'draft' => 'array',
            'answered_at' => 'datetime',
            'changed_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Poll, $this> */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return HasMany<PollVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class);
    }
}
