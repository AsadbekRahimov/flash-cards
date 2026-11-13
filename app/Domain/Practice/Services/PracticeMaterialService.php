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
use App\Models\PracticeItemVersion;
use App\Models\Word;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
 * The fitness of a task is not a filter of the query but two columns of it (`lock_dead`, `lock_unseen`: a target word is
 * inactive / not introduced yet, see {@see taskStates()}) that make its state - 0 fit, 1 LOCKED, 2 DEAD - and the rows are
 * sorted out in PHP ({@see stock()}: ONE rule for every reader). The locked tasks are what the filter used to hide: a mode that
 * is short of tasks can then tell "the tasks are there, the words are not learned" (`items_locked`) from "there are no such
 * tasks" (`no_items`) at no query more ({@see shortageReason()}).
 *
 * Two readers of the sorted tasks. {@see eligibleItems()} - the versions as models, for a session that is being opened (the
 * snapshot needs everything). {@see eligibleRows()} - plain rows of the same versions (`content` decoded, no model), for the
 * COUNTS of the hub and of the options of a battle: nothing is hydrated, and the versions of the locked and the unvoiced
 * tasks are not read at all ({@see stock()} knows the audio status from the task row).
 *
 * Everything read here is remembered by the {@see MaterialScope} it was read for: the four modes of a hub ask for the
 * same words and tasks, and the database is asked once. The tasks of ALL the types come in one pass.
 */
final class PracticeMaterialService
{
    /** The state of a task (see {@see lockStateOf()}): every target word is active and introduced ... */
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
     * The words the learner has been introduced to, in the scope, with the translations for the locales. A scope counted over a
     * sample ({@see MaterialScope::$wordSample}) gets the first `wordSample` of them by the id (one more is asked for to know there
     * are more) and remembers that the list was cut: whoever is short of material on it must ask again without the sample.
     *
     * @return list<MaterialWord>
     */
    public function introducedWords(MaterialScope $scope, Learner $learner, LocaleSet $locales): array
    {
        return $this->introduced($scope, $learner, $locales, false);
    }

    /**
     * Whether the learner has any introduced word in the scope: a cut list says so as well as the whole one, so it is not "read
     * from a cut list" ({@see MaterialScope::noteTruncatedRead()}).
     */
    public function hasIntroducedWords(MaterialScope $scope, Learner $learner, LocaleSet $locales): bool
    {
        return $scope->remember($this->introducedKey($learner, $locales), fn (): array => $this->loadIntroduced($scope, $learner, $locales, false, $this->introducedKey($learner, $locales))) !== [];
    }

    /**
     * The introduced words that have an audio file (`audio_path` is set; whether it is the file of the CURRENT text is the mode's
     * business). For a scope counted over a sample the voiced words are asked for apart: the first `wordSample` words of the learner
     * may have none while the next ones do, so counting the voiced among them would be wrong. Costs no query when the whole list of the
     * introduced words is already there and was not cut.
     *
     * @return list<MaterialWord>
     */
    public function introducedVoicedWords(MaterialScope $scope, Learner $learner, LocaleSet $locales): array
    {
        $all = $this->introducedKey($learner, $locales);
        if ($scope->has($all) && ! $scope->wasTruncated($all)) {
            return array_values(array_filter($this->introducedWords($scope, $learner, $locales), static fn (MaterialWord $word): bool => $word->audioPath !== null));
        }

        return $this->introduced($scope, $learner, $locales, true);
    }

    /**
     * Every active word of the scope (a battle: the progress of the players is not looked at). A scope counted over a sample reads
     * the words of the first lessons of the course (study order, from the cached tree) that make up more than the sample, the first
     * `wordSample` of them by the id, and remembers that the list was cut. A plain `order by id limit` over the whole course would
     * walk the primary key of `words` through nearly the whole table on PostgreSQL.
     *
     * @return list<MaterialWord>
     */
    public function allWords(MaterialScope $scope, LocaleSet $locales): array
    {
        $key = 'words:all:'.implode(',', $locales->locales);

        return $this->noted($scope, $key, $scope->remember($key, function () use ($scope, $locales, $key): array {
            $query = $this->wordQuery($scope);
            $sample = $scope->wordSample;
            $lessonIds = $sample === null ? null : $this->lessonsOfSample($scope, $locales, $sample);
            if ($lessonIds !== null) {
                $query->whereIn('words.lesson_id', $lessonIds);
                $scope->markTruncated($key); // the lessons behind them are not read
            }

            return $this->materialWords($this->cut($scope, $key, $query->get(), $sample), $locales);
        }));
    }

    /**
     * The published versions of the tasks of the type, in the scope, whose target words are all introduced to the learner
     * (`$learner = null`: all active). A dictation only with a ready audio. By the id of the task.
     *
     * @return list<PracticeItemVersion>
     */
    public function eligibleItems(MaterialScope $scope, PracticeItemType $type, ?Learner $learner): array
    {
        /** @var array<string, list<PracticeItemVersion>> $models */
        $models = $scope->remember('items:models:'.($learner->id ?? 0), function () use ($scope, $learner): array {
            $ready = $this->stock($scope, $learner)['ready'];
            $ids = [];
            foreach ($ready as $list) {
                foreach ($list as $row) {
                    $ids[] = $row['version_id'];
                }
            }

            /** @var array<int, PracticeItemVersion> $versions */
            $versions = $ids === [] ? [] : PracticeItemVersion::query()->whereIn('id', $ids)->get()->keyBy('id')->all();

            $byType = [];
            foreach ($ready as $kind => $list) {
                foreach ($list as $row) {
                    if (isset($versions[$row['version_id']])) {
                        $byType[$kind][] = $versions[$row['version_id']];
                    }
                }
            }

            return $byType;
        });

        return $models[$type->value] ?? [];
    }

    /**
     * The same versions as {@see eligibleItems()}, as plain rows - for counting what a mode has, not for playing it. A scope made
     * for counting ({@see MaterialScope::forCount()}) reads one query, no model, only the columns a count needs (the `content`
     * decoded: whether a task can be played is decided by it); any other scope is going to play, its models are read anyway and
     * the rows are made of them - opening a session costs no query more than it did.
     *
     * @return list<array{id: int, item_id: int, audio_key: string|null, content: array<array-key, mixed>}>
     */
    public function eligibleRows(MaterialScope $scope, PracticeItemType $type, ?Learner $learner): array
    {
        if (! $scope->countOnly) {
            $rows = [];
            foreach ($this->eligibleItems($scope, $type, $learner) as $version) {
                $rows[] = ['id' => $version->id, 'item_id' => $version->item_id, 'audio_key' => $version->audio_key, 'content' => $version->content];
            }

            return $rows;
        }

        /** @var array<string, list<array{id: int, item_id: int, audio_key: string|null, content: array<array-key, mixed>}>> $rows */
        $rows = $scope->remember('items:rows:'.($learner->id ?? 0), function () use ($scope, $learner): array {
            $ready = $this->stock($scope, $learner)['ready'];
            $ids = [];
            foreach ($ready as $list) {
                foreach ($list as $row) {
                    $ids[] = $row['version_id'];
                }
            }

            $versions = [];
            if ($ids !== []) {
                foreach (DB::table('practice_item_versions')->select('id', 'item_id', 'audio_key', 'content')->whereIn('id', $ids)->get() as $version) {
                    $content = is_string($version->content) ? json_decode($version->content, true) : $version->content;
                    $versions[(int) $version->id] = [
                        'id' => (int) $version->id,
                        'item_id' => (int) $version->item_id,
                        'audio_key' => is_string($version->audio_key) ? $version->audio_key : null,
                        'content' => is_array($content) ? $content : [],
                    ];
                }
            }

            $byType = [];
            foreach ($ready as $kind => $list) {
                foreach ($list as $row) {
                    if (isset($versions[$row['version_id']])) {
                        $byType[$kind][] = $versions[$row['version_id']];
                    }
                }
            }

            return $byType;
        });

        return $rows[$type->value] ?? [];
    }

    /** Dictations that would be eligible but whose audio is not ready: the reason `audio_pending` of the availability. */
    public function pendingAudioItems(MaterialScope $scope, ?Learner $learner): int
    {
        return $this->stock($scope, $learner)['pending_audio'];
    }

    /**
     * Published tasks of the type that exist in the scope but are locked: a target word is not introduced to the learner yet and
     * none is inactive. A dictation counts only with its audio ready. None for a battle (no learner, no progress).
     */
    public function lockedItems(MaterialScope $scope, PracticeItemType $type, ?Learner $learner): int
    {
        return $this->stock($scope, $learner)['locked'][$type->value] ?? 0;
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

        $rows = $this->taskStates($learner)->whereIn('practice_items.id', $itemIds)->get();

        $playable = 0;
        foreach ($rows as $row) {
            $voiced = $row->type !== PracticeItemType::Dictation->value || $row->audio_status === AudioStatus::Ready->value;
            if ($row->status === PracticeItemStatus::Published->value && $row->published_version_id !== null && $this->lockStateOf($row) === self::FIT && $voiced) {
                $playable++;
            }
        }

        return $playable + (count($itemIds) - count($rows));
    }

    /**
     * How much material each mode has in the scope: the hub and the options of a battle. The handlers report; they read the
     * material through this service, so the whole answer costs the queries of the words and of the tasks - once.
     *
     * A scope made by {@see MaterialScope::forCount()} for a whole course counts over a sample of its words
     * (`practice.count_sample`): a mode that is available on the sample is available on all the words (the words of the sample are
     * among them; see the modes), and its `available` is "at least" - the count of the sample. A mode that is short on a CUT list is
     * counted again over every word, so `state`, `reason` and the numbers of a shortage are exact. Modes of tasks read no words: no
     * cut list, no second count.
     *
     * @return array<string, ModeAvailability> mode value => availability, in the order of the modes
     */
    public function availability(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): array
    {
        $report = [];
        $full = null;
        foreach ($this->modes->all() as $handler) {
            $scope->takeTruncatedRead();
            $mode = $handler->availability($scope, $learner, $locales);

            // Counted over a sample: an available mode is available (the sample is a part of the words), a short one is short only if
            // the handler read no cut list - else the exact answer comes from the whole material (one scope for all such modes).
            if (! $mode->isAvailable && $scope->takeTruncatedRead()) {
                $full ??= $scope->withoutSample();
                $mode = $handler->availability($full, $learner, $locales);
            }

            $report[$handler->mode()->value] = $mode;
        }

        return $report;
    }

    private function introducedKey(Learner $learner, LocaleSet $locales): string
    {
        return "words:introduced:{$learner->id}:".implode(',', $locales->locales);
    }

    /** @return list<MaterialWord> */
    private function introduced(MaterialScope $scope, Learner $learner, LocaleSet $locales, bool $voiced): array
    {
        $key = $voiced ? "words:voiced:{$learner->id}:".implode(',', $locales->locales) : $this->introducedKey($learner, $locales);

        return $this->noted($scope, $key, $scope->remember($key, fn (): array => $this->loadIntroduced($scope, $learner, $locales, $voiced, $key)));
    }

    /** @return list<MaterialWord> */
    private function loadIntroduced(MaterialScope $scope, Learner $learner, LocaleSet $locales, bool $voiced, string $key): array
    {
        $query = $this->wordQuery($scope)
            ->join('word_repetitions', 'word_repetitions.word_id', '=', 'words.id')
            ->where('word_repetitions.learner_id', $learner->id);
        if ($voiced) {
            $query->whereNotNull('words.audio_path');
        }
        $sample = $scope->wordSample;
        if ($sample !== null) {
            $query->limit($sample + 1);
        }

        return $this->materialWords($this->cut($scope, $key, $query->get(), $sample), $locales);
    }

    /**
     * The rows without the one that only proved there are more than `$sample` (that one makes the list "cut").
     *
     * @param  Collection<int, Word>  $rows
     * @return Collection<int, Word>
     */
    private function cut(MaterialScope $scope, string $key, Collection $rows, ?int $sample): Collection
    {
        if ($sample === null || $rows->count() <= $sample) {
            return $rows;
        }
        $scope->markTruncated($key);

        return $rows->take($sample);
    }

    /**
     * The lessons whose words make up the sample of a whole course: the first ones in study order, until their active words are more
     * than the sample. Null: the whole course is not bigger than the sample (or the tree is empty) - nothing to cut.
     *
     * @return list<int>|null
     */
    private function lessonsOfSample(MaterialScope $scope, LocaleSet $locales, int $sample): ?array
    {
        $ids = [];
        $sum = 0;
        foreach ($this->catalog->tree($scope->course, $locales->locales[0] ?? 'ru') as $lesson) {
            if ($lesson['total'] < 1) {
                continue;
            }
            $ids[] = $lesson['lesson_id'];
            $sum += $lesson['total'];
            if ($sum > $sample) {
                return $ids;
            }
        }

        return null;
    }

    /**
     * Makes a read of a cut list known to the scope (the mode that read it may then have to be counted over every word).
     *
     * @param  list<MaterialWord>  $words
     * @return list<MaterialWord>
     */
    private function noted(MaterialScope $scope, string $key, array $words): array
    {
        if ($scope->wasTruncated($key)) {
            $scope->noteTruncatedRead();
        }

        return $words;
    }

    /** @return Builder<Word> the active words of the scope: only the columns the modes use */
    private function wordQuery(MaterialScope $scope): Builder
    {
        $query = Word::query()
            ->select('words.id', 'words.lesson_id', 'words.word', 'words.translation', 'words.audio_path', 'words.example')
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $scope->course->id)
            ->where('words.is_active', true)
            ->orderBy('words.id');

        if ($scope->lesson !== null) {
            $query->where('lessons.id', $scope->lesson->id);
        }
        if ($scope->stage !== null) {
            $query->where('lessons.stage_id', $scope->stage->id);
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

            $material[] = new MaterialWord($word->id, $word->lesson_id, $word->word, $word->translation, $word->audio_path, $translations, $word->example);
        }

        return $material;
    }

    /**
     * The published tasks of ALL the types for the scope sorted out by their state - ONE query, no version read: the audio status
     * of the task's version comes with the task. Ready: the version ids a learner can play now (a dictation only voiced), by type, by
     * the id of the task; pending audio: fit dictations that are not voiced; locked: LOCKED tasks (a dictation only voiced), by type.
     *
     * @return array{ready: array<string, list<array{version_id: int, item_id: int}>>, pending_audio: int, locked: array<string, int>}
     */
    private function stock(MaterialScope $scope, ?Learner $learner): array
    {
        return $scope->remember('items:'.($learner->id ?? 0), function () use ($scope, $learner): array {
            $query = $this->taskStates($learner)
                ->where('practice_items.course_id', $scope->course->id)
                ->where('practice_items.status', PracticeItemStatus::Published->value)
                ->whereNotNull('practice_items.published_version_id')
                ->orderBy('practice_items.id');

            if ($scope->lesson !== null) {
                $query->where('practice_items.lesson_id', $scope->lesson->id);
            }
            if ($scope->stage !== null) {
                // The tasks of the lessons of the stage; a task of the whole course (no lesson) is not a task of this city.
                $query->whereIn('practice_items.lesson_id', Lesson::query()->select('id')->where('stage_id', $scope->stage->id));
            }
            if ($scope->retry !== null) {
                $query->whereIn('practice_items.id', $scope->retry->itemIds);
            }

            $ready = [];
            $locked = [];
            $pendingAudio = 0;
            foreach ($query->get() as $item) {
                $state = $this->lockStateOf($item);
                if ($state === self::DEAD) {
                    continue; // a target word is switched off: nothing the learner does opens this task
                }
                $type = (string) $item->type;
                if ($state === self::LOCKED && $type !== PracticeItemType::Dictation->value) {
                    $locked[$type] = ($locked[$type] ?? 0) + 1;

                    continue;
                }

                if ($item->version_id === null) {
                    continue;
                }
                $voiced = $type !== PracticeItemType::Dictation->value || $item->audio_status === AudioStatus::Ready->value;
                if ($state === self::LOCKED) {
                    $locked[$type] = ($locked[$type] ?? 0) + ($voiced ? 1 : 0);

                    continue;
                }
                if (! $voiced) {
                    $pendingAudio++;

                    continue;
                }
                $ready[$type][] = ['version_id' => (int) $item->version_id, 'item_id' => (int) $item->id];
            }

            return ['ready' => $ready, 'pending_audio' => $pendingAudio, 'locked' => $locked];
        });
    }

    /**
     * The tasks (`practice_items`) with what their state is made of, in ONE pass over their target words (no sub-select per task):
     * `lock_dead` - 1 when a target word is inactive; `lock_unseen` - 1 when a target word is not introduced to the learner (no
     * `word_repetitions` row, in any state; a battle - `$learner = null` - knows only the first, the column is 0). Also the
     * published version of the task: `version_id` (null: it is gone), `audio_status`, `audio_key`. A task without target words
     * is fit. Plain rows, the learner is a binding. The caller adds the conditions and the order.
     */
    private function taskStates(?Learner $learner): QueryBuilder
    {
        $query = DB::table('practice_items')
            ->leftJoin('practice_item_versions as published', 'published.id', '=', 'practice_items.published_version_id')
            ->leftJoinSub(
                DB::table('practice_item_words as target_words')
                    ->join('words as target', 'target.id', '=', 'target_words.word_id')
                    ->select('target_words.item_id', 'target.id as word_id', 'target.is_active'),
                'targets',
                'targets.item_id',
                '=',
                'practice_items.id',
            )
            ->select(
                'practice_items.id',
                'practice_items.type',
                'practice_items.status',
                'practice_items.published_version_id',
                'published.id as version_id',
                'published.audio_status',
                'published.audio_key',
            )
            ->selectRaw('max(case when targets.is_active = false then 1 else 0 end) as lock_dead')
            ->groupBy('practice_items.id', 'published.id');

        if ($learner === null) {
            return $query->selectRaw('0 as lock_unseen');
        }

        return $query
            ->leftJoin('word_repetitions as seen', function (JoinClause $join) use ($learner): void {
                $join->on('seen.word_id', '=', 'targets.word_id')->where('seen.learner_id', '=', $learner->id);
            })
            ->selectRaw('max(case when targets.word_id is not null and seen.id is null then 1 else 0 end) as lock_unseen');
    }

    /** DEAD when a target word is inactive, else LOCKED when a target word is not introduced to the learner, else FIT (see {@see taskStates()}). */
    private function lockStateOf(object $item): int
    {
        if ((int) ($item->lock_dead ?? 0) === 1) {
            return self::DEAD;
        }

        return (int) ($item->lock_unseen ?? 0) === 1 ? self::LOCKED : self::FIT;
    }
}
