<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $telegram_user_id
 * @property int $telegram_group_id
 * @property string|null $username
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $language_code
 * @property Carbon|null $last_seen_at
 * @property bool $is_active
 */
class Student extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'telegram_user_id',
        'telegram_group_id',
        'username',
        'first_name',
        'last_name',
        'language_code',
        'last_seen_at',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<TelegramGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id');
    }

    /** @return HasMany<WordRepetition, $this> */
    public function repetitions(): HasMany
    {
        return $this->hasMany(WordRepetition::class);
    }

    /** @return HasMany<TrainingReview, $this> */
    public function trainingReviews(): HasMany
    {
        return $this->hasMany(TrainingReview::class);
    }

    /** @return HasMany<ExamAnswer, $this> */
    public function examAnswers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class);
    }

    /** @return HasMany<NotificationLog, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }
}
