<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services;

use App\Domain\Content\Services\CoursePublisher;
use App\Domain\Practice\DTO\PreparedPracticeItem;
use App\Domain\Practice\DTO\SavedPracticeItem;
use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\PracticeItemStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Enums\VersionState;
use App\Domain\Practice\Exceptions\PracticeContentException;
use App\Domain\Practice\Exceptions\PracticeItemStateException;
use App\Domain\Practice\Support\JsonbKeyOrder;
use App\Domain\Practice\Support\PracticeContentValidator;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\PracticeItemWord;
use App\Models\User;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The authoring of the tasks of the practice (docs/16 §1.3, §2.2-§2.4, §4.5): the only writer of `practice_items`,
 * `practice_item_versions` and `practice_item_words`.
 *
 * A task is a stable `key` plus APPEND-ONLY versions. A version holds everything an edit can change: the text, the target words,
 * the lesson and the level, and it never changes after it is written; an edit writes a new version (a draft). The status of the
 * task is a projection of its versions, and only this service writes it: `archived` when `archived_at` is set; else `published`
 * when there is a `published_version_id`; else the state of the current version (`draft` / `reviewed`). Learners get
 * `published_version_id`, never `current_version_id`: editing a published task leaves it `published` with the old version served,
 * until the new one is "reviewed" and "published" (the old one becomes `superseded`; the sessions and matches that hold it keep
 * playing it).
 *
 *   draft --"reviewed"--> reviewed --"publish"--> published (the previous published version: superseded)
 *   any stage -> archived (the published pointer stays, nothing is handed out) -> restored
 * A draft is never published directly. The one shortcut is the import with "publish at once" (`$publish` of {@see save()}),
 * which writes the version as published: the owner's decision that the content of a file may skip the review is confirmed by
 * the option of the person who runs the import, never by the file.
 *
 * The PROJECTION. `practice_items.lesson_id`, `practice_items.level` and the rows of `practice_item_words` are what the material of
 * the practice reads (PracticeMaterialService), and they are the place and the target words of ONE version: the published one when
 * the task has it, else the current one. Only three moves write them: {@see write()} for a task that has no published version
 * (every new version is the current one), {@see makePublished()} (the version that becomes the published one, in the same
 * transaction), and nothing else - a draft of a published task changes none of the three, so it cannot take the task away from the
 * learners who get the published version, move it to another lesson or, with no target words, hand it to everyone. Archiving and
 * restoring do not move the published pointer, hence not the projection either.
 *
 * What identifies a change: a new version is written when ANY of the four differs from the current version's - the text, the
 * target words, the lesson, the level. Saving what is already there writes nothing.
 *
 * Every write runs in one transaction that takes the lock of the course FIRST (CoursePublisher::lockForWriting, docs/16 §9
 * rule 12), then the tasks, the versions, the words and the audit trail. A new version of a dictation is queued for voicing
 * by PracticeAudioService::schedule (a job after the commit).
 */
final class PracticeItemService
{
    public const ACTION_REVIEWED = 'practice_item.reviewed';

    public const ACTION_PUBLISHED = 'practice_item.published';

    public const ACTION_ARCHIVED = 'practice_item.archived';

    public const ACTION_RESTORED = 'practice_item.restored';

    /** @var list<string> */
    public const LEVELS = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'];

    public function __construct(
        private readonly CoursePublisher $publisher,
        private readonly PracticeContentValidator $validator,
        private readonly PracticeAudioService $audio,
    ) {}

    /**
     * A new version (a draft) of a task, from the editor or the import; `$item` null makes a new task.
     *
     * `$data`: `type` and `key` (a new task; an existing one keeps its own), `lesson_id`, `level`, `word_ids` (the target
     * words: active words of the course) and `content` in the author's form (see {@see PracticeContentValidator}).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws PracticeContentException 422 `{field, code}`, the codes of docs/16 §4.5
     * @throws PracticeItemStateException the task is archived
     */
    public function saveVersion(?PracticeItem $item, Course $course, array $data, ?User $by): PracticeItemVersion
    {
        return $this->save($item, $course, $data, $by)->version;
    }

    /**
     * {@see saveVersion()} that also tells what it did, optionally writing the version as published at once (the import).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws PracticeContentException
     * @throws PracticeItemStateException
     * @throws InvalidArgumentException `$item` is a task of another course than `$course`
     */
    public function save(?PracticeItem $item, Course $course, array $data, ?User $by, bool $publish = false): SavedPracticeItem
    {
        return DB::transaction(function () use ($item, $course, $data, $by, $publish): SavedPracticeItem {
            $this->lockCourse($course);

            // Re-read after the lock: whoever held it before us may have moved the pointers of the task.
            $fresh = $item === null ? null : (PracticeItem::query()->whereKey($item->id)->first() ?? throw PracticeItemStateException::gone());

            if ($fresh !== null) {
                // The lock above is the lock of `$course`: a task of another course would be written under the wrong one.
                if ($fresh->course_id !== $course->id) {
                    throw new InvalidArgumentException("The task {$fresh->id} belongs to the course {$fresh->course_id}, not to the course {$course->id}.");
                }

                // An archived task is not edited, whatever the text says (the form of an archived task is switched off and sends none).
                $this->assertNotArchived($fresh);
            }

            $saved = $this->write($course, $fresh, $this->prepare($course, $data, $fresh), $by, $publish);

            $item?->setRawAttributes($saved->item->getAttributes(), true);

            return $saved;
        });
    }

    /**
     * The checks of the place and of the text (docs/16 §4.5), without writing: the key, the type, the lesson, the level, the
     * target words and the content. For a task that exists the key and the type are its own. Call it under the lock of the
     * course ({@see save()} and the importer do): what it reads is what {@see write()} then relies on.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws PracticeContentException
     */
    public function prepare(Course $course, array $data, ?PracticeItem $item = null): PreparedPracticeItem
    {
        if ($course->owner_type === 'learner') {
            $this->fail(PracticeContentException::COURSE_INVALID, 'course', 'A deck of a learner has no practice tasks.');
        }

        $type = $item->type ?? PracticeItemType::tryFrom(is_string($data['type'] ?? null) ? $data['type'] : '');
        if ($type === null || ($item !== null && isset($data['type']) && $data['type'] !== $item->type->value)) {
            $this->fail(PracticeContentException::TYPE_INVALID, 'type', 'The type of a task is collocation, sentence or dictation, and does not change.');
        }

        $key = $item !== null ? $item->key : $this->newKey($course, $data['key'] ?? null);

        $content = $data['content'] ?? [];

        return new PreparedPracticeItem(
            $type,
            $key,
            $this->lessonId($course, $data['lesson_id'] ?? null),
            $this->level($data['level'] ?? null),
            $this->wordIds($course, $data['word_ids'] ?? []),
            $this->validator->canonical($type, is_array($content) ? $content : []),
        );
    }

    /**
     * The stricter checks of publishing the task as prepared ("П" rows of §4.5): a target word, a text in every language.
     *
     * @throws PracticeContentException
     */
    public function assertPublishable(PreparedPracticeItem $prepared): void
    {
        if ($prepared->wordIds === []) {
            $this->fail(PracticeContentException::TARGET_WORDS_REQUIRED, 'target_words', 'A task needs at least one target word to be published.');
        }

        $this->validator->assertPublishable($prepared->type, $prepared->content);
    }

    /**
     * Writes a prepared task: the task itself when it is new, a new version when the text, the target words, the lesson or the
     * level differ from the current version's (all four are written into the version), the projection when the task has no
     * published version yet, and the publishing when asked. Under the lock of the course; {@see save()} is the entry for a single
     * task.
     *
     * @throws PracticeContentException
     * @throws PracticeItemStateException
     */
    public function write(Course $course, ?PracticeItem $item, PreparedPracticeItem $prepared, ?User $by, bool $publish = false): SavedPracticeItem
    {
        if ($publish) {
            $this->assertPublishable($prepared);
        }

        return DB::transaction(function () use ($course, $item, $prepared, $by, $publish): SavedPracticeItem {
            $this->lockCourse($course);

            $itemCreated = $item === null;
            $current = null;

            if ($item === null) {
                $item = PracticeItem::query()->create([
                    'course_id' => $course->id,
                    'lesson_id' => $prepared->lessonId,
                    'type' => $prepared->type,
                    'key' => $prepared->key,
                    'level' => $prepared->level,
                    'status' => PracticeItemStatus::Draft,
                    'created_by_user_id' => $by?->id,
                ]);
            } else {
                $this->assertNotArchived($item);

                $current = $item->current_version_id === null ? null : PracticeItemVersion::query()->find($item->current_version_id);
            }

            if ($current === null || ! $this->sameVersion($current, $prepared)) {
                $versionCreated = true;
                $version = $this->newVersion($item, $prepared, $by);

                $item->forceFill([
                    'current_version_id' => $version->id,
                    'status' => $item->published_version_id !== null ? PracticeItemStatus::Published : PracticeItemStatus::Draft,
                ]);
                // Without a published version the task shows its current one; with it, a draft changes nothing the learners get.
                if ($item->published_version_id === null) {
                    $this->project($item, $version);
                }
                $item->save();
            } else {
                $versionCreated = false;
                $version = $current;
            }

            $published = false;
            if ($publish && $version->state !== VersionState::Published) {
                $this->makePublished($item, $version, $by);
                $published = true;
            }

            return new SavedPracticeItem($item, $version, $itemCreated, $versionCreated, $published);
        });
    }

    /**
     * The current version of the task is checked ("reviewed"): draft -> reviewed. A version that is already reviewed stays
     * so (a second click is harmless). The task becomes `reviewed` unless it is `published` (then a newer draft waits).
     *
     * `$expectedVersionId` is the version the admin was looking at (the page passes it; the import and the seeders do not): when
     * the current version is another one - an import or another editor wrote a newer one meanwhile - the move is refused, so that
     * nobody marks as checked a text nobody has read.
     *
     * @throws PracticeItemStateException the task is archived, the current version is published, or it is not the expected one
     */
    public function markReviewed(PracticeItem $item, User $by, ?string $ip = null, ?int $expectedVersionId = null): PracticeItem
    {
        return $this->transition($item, function (PracticeItem $locked) use ($by, $ip, $expectedVersionId): void {
            $this->assertNotArchived($locked);
            $this->assertShown($locked, $expectedVersionId);
            $version = $this->currentVersion($locked);

            if ($version->state === VersionState::Reviewed) {
                return;
            }
            if ($version->state !== VersionState::Draft) {
                throw PracticeItemStateException::notDraft();
            }

            $version->forceFill(['state' => VersionState::Reviewed, 'reviewed_by_user_id' => $by->id, 'reviewed_at' => CarbonImmutable::now()])->save();

            if ($locked->published_version_id === null) {
                $locked->forceFill(['status' => PracticeItemStatus::Reviewed])->save();
            }

            $this->audit(self::ACTION_REVIEWED, $locked, $by, $ip, ['version_id' => $version->id, 'version' => $version->number]);
        });
    }

    /**
     * reviewed -> published, with the strict checks of publishing; the previously published version becomes `superseded`,
     * `published_version_id` moves to the current version, and the task takes the lesson, the level and the target words of it
     * (the projection). A version that is already published stays so. The strict checks read the version itself - its own target
     * words, not the ones the task shows now. `$expectedVersionId`: see {@see markReviewed()}.
     *
     * @throws PracticeContentException a "П" rule of §4.5 (a target word, a text in every language, still valid)
     * @throws PracticeItemStateException the task is archived, the current version was not marked as reviewed, or it is not the expected one
     */
    public function publish(PracticeItem $item, User $by, ?string $ip = null, ?int $expectedVersionId = null): PracticeItem
    {
        return $this->transition($item, function (PracticeItem $locked) use ($by, $ip, $expectedVersionId): void {
            $this->assertNotArchived($locked);
            $this->assertShown($locked, $expectedVersionId);
            $version = $this->currentVersion($locked);

            if ($version->state === VersionState::Published) {
                return;
            }
            if ($version->state !== VersionState::Reviewed) {
                throw PracticeItemStateException::notReviewed();
            }

            $course = Course::query()->findOrFail($locked->course_id);
            $this->assertPublishable(new PreparedPracticeItem(
                $locked->type,
                $locked->key,
                $version->lesson_id,
                $version->level,
                $this->wordIds($course, $version->target_word_ids),
                $version->content,
            ));

            $superseded = $this->makePublished($locked, $version, $by);

            $this->audit(self::ACTION_PUBLISHED, $locked, $by, $ip, [
                'version_id' => $version->id,
                'version' => $version->number,
                'superseded_version_id' => $superseded,
            ]);
        });
    }

    /**
     * Any stage -> archived. `published_version_id` is kept, but an archived task is handed out to no one. Archiving twice
     * changes nothing.
     */
    public function archive(PracticeItem $item, User $by, ?string $ip = null): PracticeItem
    {
        return $this->transition($item, function (PracticeItem $locked) use ($by, $ip): void {
            if ($locked->status === PracticeItemStatus::Archived) {
                return;
            }

            $from = $locked->status;
            $locked->forceFill(['status' => PracticeItemStatus::Archived, 'archived_at' => CarbonImmutable::now()])->save();

            $this->audit(self::ACTION_ARCHIVED, $locked, $by, $ip, ['from' => $from->value]);
        });
    }

    /**
     * archived -> published when there is a published version, else the state of the current version. The task is as it was
     * before it went to the archive (a version published meanwhile is impossible: the archived task is not published), so the
     * published pointer, and with it the projection, is the same: restoring moves neither.
     *
     * @throws PracticeItemStateException the task is not archived
     */
    public function restore(PracticeItem $item, User $by, ?string $ip = null): PracticeItem
    {
        return $this->transition($item, function (PracticeItem $locked) use ($by, $ip): void {
            if ($locked->status !== PracticeItemStatus::Archived) {
                throw PracticeItemStateException::notArchived();
            }

            $current = $locked->current_version_id === null ? null : PracticeItemVersion::query()->find($locked->current_version_id);
            $status = match (true) {
                $locked->published_version_id !== null => PracticeItemStatus::Published,
                $current?->state === VersionState::Reviewed => PracticeItemStatus::Reviewed,
                default => PracticeItemStatus::Draft,
            };

            $locked->forceFill(['status' => $status, 'archived_at' => null])->save();

            $this->audit(self::ACTION_RESTORED, $locked, $by, $ip, ['to' => $status->value]);
        });
    }

    /**
     * "Generate the audio again": queues the voicing, forced even if it is ready, of the PUBLISHED version of a dictation (the
     * one the learners get - a published version whose audio failed cannot be played) and of the CURRENT one (the one the admin
     * edits) when that is another version; each at most once. Nothing for another type or an archived task.
     *
     * @throws PracticeItemStateException the task is archived
     */
    public function regenerateAudio(PracticeItem $item): void
    {
        $this->assertNotArchived($item);

        if ($item->type !== PracticeItemType::Dictation) {
            return;
        }

        $ids = array_values(array_unique(array_filter([$item->published_version_id, $item->current_version_id], static fn (?int $id): bool => $id !== null)));
        if ($ids === []) {
            return;
        }

        foreach (PracticeItemVersion::query()->whereIn('id', $ids)->orderBy('id')->get() as $version) {
            if ($version->audio_status !== AudioStatus::None) {
                $this->audio->schedule($version, force: true);
            }
        }
    }

    // --- internals --------------------------------------------------------------------------------------------------

    /**
     * Runs `$change` on the task re-read under the lock of its course, and hands the passed model the new state (the panel
     * shows it again right away).
     *
     * @param  Closure(PracticeItem): void  $change
     */
    private function transition(PracticeItem $item, Closure $change): PracticeItem
    {
        return DB::transaction(function () use ($item, $change): PracticeItem {
            $course = Course::query()->whereKey($item->course_id)->first() ?? throw PracticeItemStateException::gone();
            $this->lockCourse($course);

            $locked = PracticeItem::query()->whereKey($item->id)->first() ?? throw PracticeItemStateException::gone();
            $change($locked);

            $item->setRawAttributes($locked->getAttributes(), true);
            $item->unsetRelations();

            return $item;
        });
    }

    private function lockCourse(Course $course): void
    {
        if ($this->publisher->lockForWriting($course) === null) {
            $this->fail(PracticeContentException::COURSE_INVALID, 'course', 'The course does not exist.');
        }
    }

    private function assertNotArchived(PracticeItem $item): void
    {
        if ($item->status === PracticeItemStatus::Archived) {
            throw PracticeItemStateException::archived();
        }
    }

    private function currentVersion(PracticeItem $item): PracticeItemVersion
    {
        $version = $item->current_version_id === null ? null : PracticeItemVersion::query()->find($item->current_version_id);

        return $version ?? throw PracticeItemStateException::noVersion();
    }

    /**
     * The admin acts on the version the page showed: when the task has moved on since (an import or another editor wrote a newer
     * version), the move is refused and the page has to be opened again. No expected version (the import, a seeder) - no check.
     *
     * @throws PracticeItemStateException version_changed
     */
    private function assertShown(PracticeItem $item, ?int $expectedVersionId): void
    {
        if ($expectedVersionId !== null && $item->current_version_id !== $expectedVersionId) {
            throw PracticeItemStateException::versionChanged();
        }
    }

    /**
     * Writes the next version of the task as a draft, with the target words, the lesson and the level it stands for. A dictation
     * is born `pending`: its audio is awaited and is queued (a job after the commit) - the text is voiced under the key the job
     * computes.
     */
    private function newVersion(PracticeItem $item, PreparedPracticeItem $prepared, ?User $by): PracticeItemVersion
    {
        $dictation = $prepared->type === PracticeItemType::Dictation;

        $version = PracticeItemVersion::query()->create([
            'item_id' => $item->id,
            'number' => (int) PracticeItemVersion::query()->where('item_id', $item->id)->max('number') + 1,
            'state' => VersionState::Draft,
            'content' => $prepared->content,
            'target_word_ids' => $prepared->wordIds,
            'lesson_id' => $prepared->lessonId,
            'level' => $prepared->level,
            'audio_status' => $dictation ? AudioStatus::Pending : AudioStatus::None,
            'created_by_user_id' => $by?->id,
        ]);

        if ($dictation) {
            $this->audio->schedule($version);
        }

        return $version;
    }

    /**
     * The version becomes the published one: the previous published version (if another) is superseded, the task points at
     * this one, is `published` and shows the lesson, the level and the target words of the version (the projection, in the same
     * transaction). One statement for the task, so the PostgreSQL CHECK on its shape holds at every step.
     *
     * The target words of the version were checked by the caller ({@see write()} with `$publish`, {@see publish()}): they are
     * active words of the course, so the rows below can be written.
     *
     * @return int|null the id of the version that was superseded
     */
    private function makePublished(PracticeItem $item, PracticeItemVersion $version, ?User $by): ?int
    {
        $now = CarbonImmutable::now();
        $previous = $item->published_version_id;

        if ($previous !== null && $previous !== $version->id) {
            PracticeItemVersion::query()->whereKey($previous)->update(['state' => VersionState::Superseded->value, 'superseded_at' => $now]);
        } else {
            $previous = null;
        }

        $version->forceFill(['state' => VersionState::Published, 'published_by_user_id' => $by?->id, 'published_at' => $now])->save();
        $this->project($item, $version);
        $item->forceFill([
            'status' => PracticeItemStatus::Published,
            'current_version_id' => $version->id,
            'published_version_id' => $version->id,
            'archived_at' => null,
        ])->save();

        return $previous;
    }

    /**
     * The task takes the place and the target words of the version: `lesson_id` and `level` are set on the model (the caller saves
     * it) and the rows of `practice_item_words` are made to be the version's. The only writer of the three (see the class docblock).
     */
    private function project(PracticeItem $item, PracticeItemVersion $version): void
    {
        $item->forceFill(['lesson_id' => $version->lesson_id, 'level' => $version->level]);

        $this->syncWords($item, $this->linkedWordIds($item), $this->sortedIds($version->target_word_ids));
    }

    /**
     * @param  list<int>  $current
     * @param  list<int>  $wanted
     */
    private function syncWords(PracticeItem $item, array $current, array $wanted): void
    {
        $drop = array_values(array_diff($current, $wanted));
        if ($drop !== []) {
            PracticeItemWord::query()->where('item_id', $item->id)->whereIn('word_id', $drop)->delete();
        }

        $add = array_values(array_diff($wanted, $current));
        if ($add !== []) {
            PracticeItemWord::query()->insert(array_map(static fn (int $wordId): array => ['item_id' => $item->id, 'word_id' => $wordId], $add));
        }
    }

    /** @return list<int> the target words the task shows now (the rows of `practice_item_words`), ascending */
    private function linkedWordIds(PracticeItem $item): array
    {
        $ids = [];
        foreach (PracticeItemWord::query()->where('item_id', $item->id)->pluck('word_id') as $id) {
            $ids[] = (int) $id;
        }
        sort($ids);

        return $ids;
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<int> each id once, ascending
     */
    private function sortedIds(array $ids): array
    {
        $sorted = [];
        foreach ($ids as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $sorted[(int) $id] = (int) $id;
            }
        }
        sort($sorted);

        return $sorted;
    }

    /** The version already is what is being saved: the same text, target words, lesson and level. */
    private function sameVersion(PracticeItemVersion $version, PreparedPracticeItem $prepared): bool
    {
        return $version->lesson_id === $prepared->lessonId
            && $version->level === $prepared->level
            && $this->sortedIds($version->target_word_ids) === $this->sortedIds($prepared->wordIds)
            && $this->sameContent($version->content, $prepared->content);
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $new
     */
    private function sameContent(array $stored, array $new): bool
    {
        // jsonb keeps the keys in an order of its own: compare in that one.
        return json_encode(JsonbKeyOrder::normalize($stored)) === json_encode(JsonbKeyOrder::normalize($new));
    }

    /** @param  array<string, mixed>  $meta */
    private function audit(string $action, PracticeItem $item, ?User $by, ?string $ip, array $meta): void
    {
        AuditLog::query()->create([
            'user_id' => $by?->id,
            'action' => $action,
            'entity_type' => PracticeItem::class,
            'entity_id' => $item->id,
            'ip' => $ip,
            'meta' => ['key' => $item->key, 'type' => $item->type->value, 'course_id' => $item->course_id, ...$meta],
        ]);
    }

    // --- place of the task: key, lesson, level, words ---------------------------------------------------------------

    private function newKey(Course $course, mixed $raw): string
    {
        $key = is_string($raw) ? $raw : '';

        if (preg_match((string) config('practice.content.key_pattern'), $key) !== 1) {
            $this->fail(PracticeContentException::KEY_INVALID, 'key', 'The key is lower case latin letters, digits, dot, dash and underscore, up to 64 characters.');
        }

        if (PracticeItem::query()->where('course_id', $course->id)->where('key', $key)->exists()) {
            $this->fail(PracticeContentException::KEY_DUPLICATE, 'key', "The course already has a task with the key '{$key}'.", ['key' => $key]);
        }

        return $key;
    }

    private function lessonId(Course $course, mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $id = is_numeric($raw) ? (int) $raw : 0;

        $inCourse = $id > 0 && Lesson::query()
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('lessons.id', $id)
            ->where('stages.course_id', $course->id)
            ->exists();

        if (! $inCourse) {
            $this->fail(PracticeContentException::LESSON_NOT_IN_COURSE, 'lesson', 'The lesson is not a lesson of this course.');
        }

        return $id;
    }

    private function level(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw) || ! in_array($raw, self::LEVELS, true)) {
            $this->fail(PracticeContentException::LEVEL_INVALID, 'level', 'The level is one of A1, A2, B1, B2, C1, C2.');
        }

        return $raw;
    }

    /**
     * The target words: ids of ACTIVE words of the course (a word of any lesson of it), each once, ascending.
     *
     * @return list<int>
     *
     * @throws PracticeContentException target_word_unknown for the first id that is no active word of the course
     */
    private function wordIds(Course $course, mixed $raw): array
    {
        $wanted = [];
        foreach (is_array($raw) ? $raw : [] as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                $this->fail(PracticeContentException::TARGET_WORD_UNKNOWN, 'target_words', 'A target word is no active word of the course.', ['word' => is_scalar($id) ? (string) $id : '']);
            }
            $wanted[(int) $id] = (int) $id;
        }

        if ($wanted === []) {
            return [];
        }

        $found = [];
        foreach (Word::query()
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $course->id)
            ->where('words.is_active', true)
            ->whereIn('words.id', array_values($wanted))
            ->pluck('words.id') as $id) {
            $found[(int) $id] = true;
        }

        foreach ($wanted as $id) {
            if (! isset($found[$id])) {
                // The admin knows the word by its text; the id is all there is for a word that has gone.
                $text = Word::query()->whereKey($id)->value('word');
                $word = is_string($text) && trim($text) !== '' ? $text : (string) $id;

                $this->fail(PracticeContentException::TARGET_WORD_UNKNOWN, 'target_words', "The word '{$word}' is no active word of the course.", ['word' => $word]);
            }
        }

        $ids = array_values($wanted);
        sort($ids);

        return $ids;
    }

    /**
     * @param  array<string, int|string>  $context
     */
    private function fail(string $code, string $field, string $message, array $context = []): never
    {
        throw new PracticeContentException($code, $field, $message, $context);
    }
}
