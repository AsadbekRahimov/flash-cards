<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Polls\Enums\PollStatus;
use Database\Factories\PollFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A poll in the bot (docs/35 §1.4): a photo with a caption (or a text) and inline buttons sent to an audience of learners; our own
 * count of the votes. Made and run from `/admin` by a platform administrator only; the services are in `App\Domain\Polls`. After the
 * launch the question, the options and the picture do not change.
 *
 * @property int $id
 * @property string $title
 * @property array{ru: string, uz: string} $question
 * @property string|null $image_path file on the private `local` disk, under `polls/`
 * @property string|null $image_file_id Telegram's `file_id` after the first successful send
 * @property bool $allows_multiple
 * @property int|null $max_choices
 * @property bool $allow_change
 * @property array<string, mixed> $audience `{plan, locales, course_ids, region_codes, onboarded_only}`
 * @property PollStatus $status
 * @property Carbon|null $closes_at
 * @property Carbon|null $launched_at
 * @property Carbon|null $closed_at
 * @property int|null $created_by_user_id
 * @property int|null $launched_by_user_id
 * @property array<string, mixed>|null $results_snapshot the totals at closing
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Poll extends Model
{
    /** @use HasFactory<PollFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'title', 'question', 'image_path', 'image_file_id', 'allows_multiple', 'max_choices', 'allow_change', 'audience', 'status',
        'closes_at', 'launched_at', 'closed_at', 'created_by_user_id', 'launched_by_user_id', 'results_snapshot',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'allows_multiple' => false,
        'allow_change' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'question' => 'array',
            'allows_multiple' => 'boolean',
            'max_choices' => 'integer',
            'allow_change' => 'boolean',
            'audience' => 'array',
            'status' => PollStatus::class,
            'closes_at' => 'datetime',
            'launched_at' => 'datetime',
            'closed_at' => 'datetime',
            'created_by_user_id' => 'integer',
            'launched_by_user_id' => 'integer',
            'results_snapshot' => 'array',
        ];
    }

    /** @return HasMany<PollOption, $this> in the order of the buttons */
    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class)->orderBy('position');
    }

    /** @return HasMany<PollDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(PollDelivery::class);
    }

    /** @return HasMany<PollVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function launchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'launched_by_user_id');
    }
}
