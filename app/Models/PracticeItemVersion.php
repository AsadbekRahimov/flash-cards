<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\VersionState;
use Carbon\CarbonImmutable;
use Database\Factories\PracticeItemVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a task (docs/16 §2.3): the texts and the structure by type in `content`, the place of the task (`lesson_id`,
 * `level`) and its target words (`target_word_ids`) - none of them changes after the INSERT - and, for a dictation, the audio.
 * The audio columns are the only ones a job changes later (and `lesson_id` is set NULL when the lesson is deleted). There is no
 * `updated_at`: a version does not change. The task's own `lesson_id` / `level` and its `practice_item_words` are the projection
 * of ONE version (the published one, else the current one), written by PracticeItemService only.
 *
 * @property int $id
 * @property int $item_id
 * @property int $number
 * @property VersionState $state
 * @property array<string, mixed> $content
 * @property list<int> $target_word_ids ids of the target words (words of the course), ascending
 * @property int|null $lesson_id
 * @property string|null $level
 * @property string|null $audio_key
 * @property AudioStatus $audio_status
 * @property string|null $audio_error
 * @property CarbonImmutable|null $audio_generated_at
 * @property int|null $created_by_user_id
 * @property int|null $reviewed_by_user_id
 * @property int|null $published_by_user_id
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $superseded_at
 * @property CarbonImmutable|null $created_at
 */
class PracticeItemVersion extends Model
{
    /** @use HasFactory<PracticeItemVersionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'item_id',
        'number',
        'state',
        'content',
        'target_word_ids',
        'lesson_id',
        'level',
        'audio_key',
        'audio_status',
        'audio_error',
        'audio_generated_at',
        'created_by_user_id',
        'reviewed_by_user_id',
        'published_by_user_id',
        'reviewed_at',
        'published_at',
        'superseded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
            'number' => 'integer',
            'state' => VersionState::class,
            'content' => 'array',
            'target_word_ids' => 'array',
            'lesson_id' => 'integer',
            'audio_status' => AudioStatus::class,
            'audio_generated_at' => 'immutable_datetime',
            'created_by_user_id' => 'integer',
            'reviewed_by_user_id' => 'integer',
            'published_by_user_id' => 'integer',
            'reviewed_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PracticeItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PracticeItem::class, 'item_id');
    }
}
