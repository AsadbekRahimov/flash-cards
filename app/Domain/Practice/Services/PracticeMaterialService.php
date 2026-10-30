<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\MaterialWord;
use App\Domain\Practice\DTO\ModeAvailability;
use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\PracticeItemStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Modes\ModeRegistry;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\Word;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * What a learner (or a pair of players) may practice on (docs/16 §1.3, §4.1, §4.2): the scope - a course and maybe a lesson
 * - the words the learner has been introduced to, and the published tasks whose target words are all introduced.
 *
 * Words: active words of the course / lesson with a `word_repetitions` row of the learner in ANY state, `new` included
 * (they have been shown to the learner at least once), with their translations for the locales of the {@see LocaleSet}
 * (WordTranslationResolver: the locale, else the ru fallback): 2 queries. Tasks: `status = published`, of the course /
 * lesson, none of whose target words is inactive or not introduced (a battle - `$learner = null` - checks only that the
 * words are active), then their published versions; a dictation only with `audio_status = ready`.
 *
 * The fitness of a task is not a filter of the query but a column of it (`lock_state`: 0 fit, 1 LOCKED - a target word is not
 * introduced yet, 2 DEAD - a target word is inactive), and the rows are sorted out in PHP. The locked tasks are what the filter
 * used to hide: a mode that is short of tasks can then tell "the tasks are there, the words are not learned" (`items_locked`)
 * from "there are no such tasks" (`no_items`) at no query more ({@see shortageReason()}).
 *
 * Everything read here is remembered by the {@see MaterialScope} it was read for: the four modes of a hub ask for the
 * same words and tasks, and the database is asked once. The tasks of ALL the types come in one pass.
 */
final class PracticeMaterialService
{
    /** `lock_state` of a task: every target word is active and introduced ... */
    private const FIT = 0;

    /** ... some target word is not introduced to the learner yet (and none is inactive) ... */
    private const LOCKED = 1;

    /** ... some target word is inactive: nothing the learner does opens the task. */
    private const DEAD = 2;

    public function __construct(
        private readonly CourseCatalogService $catalog,
        private readonly WordTranslationResolver $translations,
        private readonly ModeRegistry $modes,
    ) {}

    /**
     * The course by slug, if the learner may open it (published and not private, or the learner's own deck).
     *
     * @throws PracticeException course_not_found (404): a draft, a private course of someone else and a missing one look the same
     */
    public function course(string $slug, Learner $learner): Course
    {
        return $this->catalog->findAvailable($slug, $learner) ?? throw PracticeException::courseNotFound();
    }

    /**
     * The course by id for the retry of a finished session: it must still be available to the learner.
     *
     * @throws PracticeException course_not_found (404)
     */
    public function courseById(int $id, Learner $learner): Course
    {
        $course = Course::query()->find($id);

        return $course !== null && $this->catalog->isAvailable($course, $learner) ? $course : throw PracticeException::courseNotFound();
    }

    /**
     * The course of a battle: published and not private, so a deck never plays.
     *
     * @throws PracticeException course_not_found (404)
     */
    public function courseForBattle(string $slug): Course
    {
        return $this->catalog->findAvailable($slug) ?? throw PracticeException::courseNotFound();
    }

    /**
     * The lesson of the course, with its stage.
     *
     * @param  Learner|null  $by  whose access decides what a lesson of ANOTHER course may reveal (null: a battle)
     *
     * @throws PracticeException lesson_not_found (404) | lesson_not_in_course (422): a lesson of a course the learner could not open is "not found", not "not in the course"
     */
    public function lesson(Course $course, int $lessonId, ?Learner $by = null): Lesson
    {
        $lesson = $this->catalog->lessonWithContext($lessonId);
        $stage = $lesson?->stage;
        if ($lesson === null || $stage === null) {
            throw PracticeException::lessonNotFound();
        }

        if ($stage->course_id !== $course->id) {
            $other = $stage->course;

            throw $other !== null && $this->catalog->isAvailable($other, $by)
                ? PracticeException::lessonNotInCourse()
                : PracticeException::lessonNotFound();
        }

        return $lesson;
    }

    /**
     * The words the learner has been introduced to, in the scope, with the translations for the locales.
     *
     * @return list<MaterialWord>
     */
    public function introducedWords(MaterialScope $scope, Learner $learner, LocaleSet $locales): array
    {
        return $scope->remember(
            "words:introduced:{$learner->id}:".implode(',', $locales->locales),
            fn (): array => $this->materialWords(
                $this->wordQuery($scope)
                    ->join('word_repetitions', 'word_repetitions.word_id', '=', 'words.id')
                    ->where('word_repetitions.learner_id', $learner->id)
                    ->get(),
                $locales,
            ),
        );
    }

    /**
     * Every active word of the scope (a battle: the progress of the players is not looked at).
     *
     * @return list<MaterialWord>
     */
    public function allWords(MaterialScope $scope, LocaleSet $locales): array
    {
        return $scope->remember(
            'words:all:'.implode(',', $locales->locales),
            fn (): array => $this->materialWords($this->wordQuery($scope)->get(), $locales),
        );
    }

    /**
     * The published versions of the tasks of the type, in the scope, whose target words are all introduced to the learner
     * (`$learner = null`: all active). A dictation only with a ready audio. By the id of the task.
     *
     * @return list<PracticeItemVersion>
     */
    public function eligibleItems(MaterialScope $scope, PracticeItemType $type, ?Learner $learner): array
    {
        return $this->items($scope, $learner)['ready'][$type->value] ?? [];
    }

    /** Dictations that would be eligible but whose audio is not ready: the reason `audio_pending` of the availability. */
    public function pendingAudioItems(MaterialScope $scope, ?Learner $learner): int
    {
        return $this->items($scope, $learner)['pending_audio'];
    }

    /**
     * Published tasks of the type that exist in the scope but are locked: a target word is not introduced to the learner yet and
     * none is inactive. A dictation counts only with its audio ready. None for a battle (no learner, no progress).
     */
    public function lockedItems(MaterialScope $scope, PracticeItemType $type, ?Learner $learner): int
    {
        return $this->items($scope, $learner)['locked'][$type->value] ?? 0;
    }

    /**
     * Why a mode is short of tasks: `items_locked` when the locked ones would make up the number (learning the words of the lesson
     * is the way out - the screen may say so), else `no_items` (there are no such tasks, or too few even after the words are
     * learned: the promise "they will appear" would be false).
     *
     * @param  int  $available  the tasks the learner can play now
     * @param  int  $required  how many a session needs
     */
    public function shortageReason(MaterialScope $scope, PracticeItemType $type, ?Learner $learner, int $available, int $required): string
    {
        return $learner !== null && $available + $this->lockedItems($scope, $type, $learner) >= $required
            ? ModeAvailability::REASON_ITEMS_LOCKED
            : ModeAvailability::REASON_NO_ITEMS;
    }

    /**
     * The least number of tasks (collocation, sentence, dictation) a solo session needs: `practice.solo.items.min`; the retry
     * of the mistakes needs one (docs/16 §6.1). Pairs have their own minimum (`practice.solo.pairs.min_per_round`).
     */
    public function minItems(MaterialScope $scope): int
    {
        return $scope->retry !== null ? 1 : max(1, (int) config('practice.solo.items.min'));
    }

    /**
     * How many of the tasks among the mistakes of a finished session can still be played again (docs/16 §6.1): ONE statement
     * with the very test of {@see items()} - published, no target word inactive or not introduced to the learner - and a dictation
     * with its audio ready. An id without a row says nothing (the practice never deletes a task) and is not counted as gone.
     *
     * @param  list<int>  $itemIds
     */
    public function retryItemStock(array $itemIds, Learner $learner): int
    {
        if ($itemIds === []) {
            return 0;
        }

        [$lockState, $bindings] = $this->lockStateSql($learner);
        $rows = PracticeItem::query()
            ->select('practice_items.id', 'practice_items.type', 'practice_items.status', 'practice_items.published_version_id')
            ->selectRaw($lockState.' as lock_state', $bindings)
            ->selectSub(
                PracticeItemVersion::query()->select('audio_status')->whereColumn('practice_item_versions.id', 'practice_items.published_version_id'),
                'audio_status',
            )
            ->whereIn('practice_items.id', $itemIds)
            ->get();

        $playable = 0;
        foreach ($rows as $row) {
            $voiced = $row->type !== PracticeItemType::Dictation || $row->getAttribute('audio_status') === AudioStatus::Ready->value;
            if ($row->status === PracticeItemStatus::Published && $row->published_version_id !== null && (int) $row->getAttribute('lock_state') === self::FIT && $voiced) {
                $playable++;
            }
        }

        return $playable + (count($itemIds) - count($rows));
    }

    /**
     * How much material each mode has in the scope: the hub and the options of a battle. The handlers report; they read the
     * material through this service, so the whole answer costs the queries of the words and of the tasks - once.
     *
     * @return array<string, ModeAvailability> mode value => availability, in the order of the modes
     */
    public function availability(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): array
    {
        $report = [];
        foreach ($this->modes->all() as $handler) {
            $report[$handler->mode()->value] = $handler->availability($scope, $learner, $locales);
        }

        return $report;
    }

    /** @return Builder<Word> the active words of the scope: only the columns the modes use */
    private function wordQuery(MaterialScope $scope): Builder
    {
        $query = Word::query()
            ->select('words.id', 'words.lesson_id', 'words.word', 'words.translation', 'words.audio_path')
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $scope->course->id)
            ->where('words.is_active', true)
            ->orderBy('words.id');

        if ($scope->lesson !== null) {
            $query->where('lessons.id', $scope->lesson->id);
        }
        if ($scope->retry !== null) {
            $query->whereIn('words.id', $scope->retry->wordIds);
        }

        return $query;
    }

    /**
     * @param  iterable<Word>  $words
     * @return list<MaterialWord>
     */
    private function materialWords(iterable $words, LocaleSet $locales): array
    {
        $list = [];
        foreach ($words as $word) {
            $list[] = $word;
        }

        /** @var array<string, array<int, string>> $byLocale locale => word id => the translation shown in it */
        $byLocale = [];
        foreach ($locales->locales as $locale) {
            $byLocale[$locale] = $this->translations->map($list, $locale);
        }

        $material = [];
        foreach ($list as $word) {
            $translations = [];
            foreach ($byLocale as $locale => $texts) {
                $translations[$locale] = $texts[$word->id] ?? $word->translation;
            }

            $material[] = new MaterialWord($word->id, $word->lesson_id, $word->word, $word->translation, $word->audio_path, $translations);
        }

        return $material;
    }

    /**
     * The tasks of ALL the types for the scope in two queries (the tasks with their `lock_state`, then the versions), split by type.
     *
     * @return array{ready: array<string, list<PracticeItemVersion>>, pending_audio: int, locked: array<string, int>}
     */
    private function items(MaterialScope $scope, ?Learner $learner): array
    {
        return $scope->remember('items:'.($learner->id ?? 0), function () use ($scope, $learner): array {
            [$lockState, $bindings] = $this->lockStateSql($learner);

            $query = PracticeItem::query()
                ->select('practice_items.id', 'practice_items.type', 'practice_items.published_version_id')
                ->selectRaw($lockState.' as lock_state', $bindings)
                ->where('practice_items.course_id', $scope->course->id)
                ->where('practice_items.status', PracticeItemStatus::Published->value)
                ->whereNotNull('practice_items.published_version_id')
                ->orderBy('practice_items.id');

            if ($scope->lesson !== null) {
                $query->where('practice_items.lesson_id', $scope->lesson->id);
            }
            if ($scope->retry !== null) {
                $query->whereIn('practice_items.id', $scope->retry->itemIds);
            }

            // What the learner can play now needs its version; so does a LOCKED dictation (is it voiced? then it is worth counting).
            $items = $query->get();
            $versionIds = [];
            foreach ($items as $item) {
                $state = (int) $item->getAttribute('lock_state');
                if ($state === self::FIT || ($state === self::LOCKED && $item->type === PracticeItemType::Dictation)) {
                    $versionIds[] = (int) $item->published_version_id;
                }
            }

            /** @var array<int, PracticeItemVersion> $versions */
            $versions = $versionIds === []
                ? []
                : PracticeItemVersion::query()->whereIn('id', $versionIds)->get()->keyBy('id')->all();

            $ready = [];
            $locked = [];
            $pendingAudio = 0;
            foreach ($items as $item) {
                $state = (int) $item->getAttribute('lock_state');
                if ($state === self::DEAD) {
                    continue; // a target word is switched off: nothing the learner does opens this task
                }
                if ($state === self::LOCKED && $item->type !== PracticeItemType::Dictation) {
                    $locked[$item->type->value] = ($locked[$item->type->value] ?? 0) + 1;

                    continue;
                }

                $version = $versions[(int) $item->published_version_id] ?? null;
                if ($version === null) {
                    continue;
                }
                $voiced = $item->type !== PracticeItemType::Dictation || $version->audio_status === AudioStatus::Ready;
                if ($state === self::LOCKED) {
                    $locked[$item->type->value] = ($locked[$item->type->value] ?? 0) + ($voiced ? 1 : 0);

                    continue;
                }
                if (! $voiced) {
                    $pendingAudio++;

                    continue;
                }
                $ready[$item->type->value][] = $version;
            }

            return ['ready' => $ready, 'pending_audio' => $pendingAudio, 'locked' => $locked];
        });
    }

    /**
     * The SQL of the column `lock_state` of a task, as a `case`: 2 when a target word is inactive, 1 when a target word is not
     * introduced to the learner, else 0. A battle (`$learner = null`) knows only the first. The learner is a binding; the numbers
     * are the constants of this class.
     *
     * @return array{0: string, 1: list<mixed>} the expression and its bindings, in the order of the `?` in it
     */
    private function lockStateSql(?Learner $learner): array
    {
        $inactive = $this->targetWords()->where('target.is_active', false);
        $sql = 'case when exists ('.$inactive->toSql().') then '.self::DEAD;
        $bindings = $inactive->getBindings();

        if ($learner !== null) {
            $unseen = $this->targetWords()
                ->leftJoin('word_repetitions as seen', function (JoinClause $join) use ($learner): void {
                    $join->on('seen.word_id', '=', 'target_words.word_id')->where('seen.learner_id', '=', $learner->id);
                })
                ->whereNull('seen.id');
            $sql .= ' when exists ('.$unseen->toSql().') then '.self::LOCKED;
            $bindings = [...$bindings, ...$unseen->getBindings()];
        }

        return [$sql.' else '.self::FIT.' end', $bindings];
    }

    /** The target words of the task being selected (`practice_items`): the part of a `lock_state` subquery that the two share. */
    private function targetWords(): QueryBuilder
    {
        return DB::query()
            ->select('target_words.item_id')
            ->from('practice_item_words as target_words')
            ->join('words as target', 'target.id', '=', 'target_words.word_id')
            ->whereColumn('target_words.item_id', 'practice_items.id');
    }
}
