<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LearnerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $telegram_user_id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $username
 * @property string|null $language_code
 * @property string $interface_locale
 * @property string $timezone
 * @property string|null $goal
 * @property string|null $level
 * @property int|null $active_course_id
 * @property int $daily_goal
 * @property string|null $reminder_time
 * @property Carbon|null $next_reminder_at
 * @property Carbon|null $dm_blocked_at
 * @property string $onboarding_state
 * @property Carbon|null $onboarding_completed_at
 * @property string|null $acquisition_source
 * @property string|null $start_payload
 * @property int|null $referrer_learner_id
 * @property string|null $referral_code
 * @property int $streak_current
 * @property int $streak_best
 * @property Carbon|null $streak_last_date
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $blocked_at
 * @property array<string, mixed> $meta
 */
class Learner extends Model
{
    /** @use HasFactory<LearnerFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'telegram_user_id',
        'first_name',
        'last_name',
        'username',
        'language_code',
        'interface_locale',
        'timezone',
        'goal',
        'level',
        'active_course_id',
        'daily_goal',
        'reminder_time',
        'next_reminder_at',
        'dm_blocked_at',
        'onboarding_state',
        'onboarding_completed_at',
        'acquisition_source',
        'start_payload',
        'referrer_learner_id',
        'referral_code',
        'streak_current',
        'streak_best',
        'streak_last_date',
        'last_seen_at',
        'blocked_at',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'active_course_id' => 'integer',
            'daily_goal' => 'integer',
            'next_reminder_at' => 'datetime',
            'dm_blocked_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'streak_current' => 'integer',
            'streak_best' => 'integer',
            'streak_last_date' => 'date',
            'last_seen_at' => 'datetime',
            'blocked_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function activeCourse(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'active_course_id');
    }

    /** @return BelongsTo<Learner, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referrer_learner_id');
    }

    /**
     * The learners this one invited (docs/13 §1.5 `referrals`, as the inviter).
     *
     * @return HasMany<Referral, $this>
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_learner_id');
    }

    /**
     * The referral this learner came through (as the invited one); at most one per learner.
     *
     * @return HasOne<Referral, $this>
     */
    public function referral(): HasOne
    {
        return $this->hasOne(Referral::class, 'referee_learner_id');
    }

    /** @return HasMany<GroupMember, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    /** @return BelongsToMany<TelegramGroup, $this> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(TelegramGroup::class, 'group_members')
            ->withPivot(['status', 'joined_at', 'left_at'])
            ->withTimestamps();
    }

    /**
     * Memberships in organizations (seats, docs/13 §1.3).
     *
     * @return HasMany<OrganizationMember, $this>
     */
    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
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

    /** @return HasMany<ExamQuestionAttempt, $this> */
    public function examQuestionAttempts(): HasMany
    {
        return $this->hasMany(ExamQuestionAttempt::class);
    }

    /** @return HasMany<NotificationLog, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    /** @return HasMany<LearnerDailyStats, $this> */
    public function dailyStats(): HasMany
    {
        return $this->hasMany(LearnerDailyStats::class);
    }

    /** @return HasMany<ProductEvent, $this> */
    public function productEvents(): HasMany
    {
        return $this->hasMany(ProductEvent::class);
    }

    /** @return HasMany<Offer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'subscriber_id')
            ->where('subscriber_type', 'learner');
    }

    /** @return HasMany<PromoRedemption, $this> */
    public function promoRedemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }
}
