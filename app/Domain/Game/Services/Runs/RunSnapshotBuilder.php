<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Runs;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\DeckTitles;
use App\Domain\Content\Services\WordAudioService;
use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Game\DTO\AuthoredCandidate;
use App\Domain\Game\DTO\RunLimits;
use App\Domain\Game\DTO\RunPool;
use App\Domain\Game\DTO\RunPoolWord;
use App\Domain\Game\DTO\RunSnapshot;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Tasks\PracticeTaskAdapter;
use App\Domain\Game\Support\CityNames;
use App\Domain\Game\Support\RunComposer;
use App\Domain\Learning\Services\GapFillBuilder;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\MaterialWord;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Services\Modes\PairsMode;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Domain\Practice\Support\SeededShuffle;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItemVersion;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * The snapshot of a run of a stop (docs/18 §12.3.1): the pools - read BEFORE the transaction, without a lock - and the composition.
 *
 * 1. The words: `PracticeMaterialService::introducedWords` of the lesson; none - the stop is locked (409 `stop_locked`: the lesson was
 *    not started in the cards); fewer than `min_elements` - the pool widens to the city (`MaterialScope::forStage`, `scope_widened`);
 *    still fewer - 422 `game_not_enough_material {reason: no_words}`.
 * 2. The authored tasks: `eligibleItems` of the three types for the lesson, then for the city; no more than `authored_max`, no more
 *    than one dictation, chosen by the seed; built by the handlers of the practice (`PracticeTaskAdapter`), which drop a dictation
 *    whose clip is missing.
 * 3. The distractors: one query - up to `distractor_pool` active words of the course outside the pool, and as many of the public
 *    courses of the platform (`Course::scopePublicPlatform`) - with their translations.
 *
 * The seed is `random_bytes(32)`, used here and NOT stored (the layout is in the snapshot); a test passes its own and gets the same
 * composition every time.
 */
final class RunSnapshotBuilder
{
    public function __construct(
        private readonly PracticeMaterialService $material,
        private readonly PracticeTaskAdapter $authored,
        private readonly WordAudioService $wordAudio,
        private readonly WordTranslationResolver $translations,
        private readonly GapFillBuilder $gaps,
        private readonly CourseCatalogService $catalog,
        private readonly DeckTitles $titles,
    ) {}

    /**
     * @throws GameException stop_locked | game_not_enough_material
     */
    public function forStop(Learner $learner, Course $course, Lesson $lesson, LocaleSet $locales, ?string $seed = null): RunSnapshot
    {
        $seed ??= SeededShuffle::newSeed();
        $limits = RunLimits::for(RunKind::Stop);
        $stage = $lesson->stage ?? throw new LogicException("The lesson {$lesson->id} has no stage.");

        $pool = $this->poolForStop($learner, $course, $lesson, $stage, $locales, $seed, $limits);
        $composition = RunComposer::compose($pool, $seed, $limits);
        if ($composition->count() < $limits->minElements) {
            throw GameException::notEnoughMaterial($composition->count(), $limits->minElements, 'no_words');
        }

        $courseTitle = [];
        $stageTitle = [];
        foreach ($locales->locales as $locale) {
            $courseTitle[$locale] = $this->catalog->title($course, $locale);
            $name = $this->titles->stageIn($course, $locale, $stage->title, $stage->title_uz);
            if ($name !== null) {
                $stageTitle[$locale] = $name;
            }
        }

        $snapshot = new RunSnapshot(
            kind: RunKind::Stop,
            locales: $locales->locales,
            elements: $composition->elements,
            events: $composition->events,
            courseSlug: $course->slug,
            courseTitle: $courseTitle,
            stageNumber: $stage->number,
            stageTitle: $stageTitle,
            city: CityNames::key($stage->number),
            lessonNumber: $lesson->number,
            lessonTitles: $this->titles->lessonTitles($course, $lesson, $locales->locales),
            scopeWidened: $pool->scopeWidened,
            flasksMax: $limits->flasks,
        );
        $snapshot->assertFits();

        return $snapshot;
    }

    /**
     * The pool of a stop (public for the tests of the composition).
     *
     * @throws GameException stop_locked | game_not_enough_material
     */
    public function poolForStop(Learner $learner, Course $course, Lesson $lesson, Stage $stage, LocaleSet $locales, string $seed, RunLimits $limits): RunPool
    {
        $lessonScope = new MaterialScope($course, $lesson);
        $words = $this->material->introducedWords($lessonScope, $learner, $locales);
        if ($words === []) {
            throw GameException::stopLocked();
        }

        $widened = false;
        $stageScope = null;
        if (count($words) < $limits->minElements) {
            $stageScope = MaterialScope::forStage($course, $stage);
            $words = $this->material->introducedWords($stageScope, $learner, $locales);
            $widened = true;
            if (count($words) < $limits->minElements) {
                throw GameException::notEnoughMaterial(count($words), $limits->minElements, 'no_words');
            }
        }

        $authored = $limits->withAuthored && $limits->authoredMax > 0
            ? $this->authoredCandidates($learner, $course, $lessonScope, $stageScope, $locales, $seed, $limits->authoredMax)
            : [];

        $poolWords = array_map(fn (MaterialWord $word): RunPoolWord => $this->poolWord($word, $locales, RunPoolWord::TIER_POOL), $words);

        return new RunPool($locales, $poolWords, $authored, $this->distractors($course, $words, $locales), $widened);
    }

    /**
     * Up to `$max` authored tasks of the lesson (then of the city), no more than one dictation, in the order of the seed, built.
     *
     * @return list<AuthoredCandidate>
     */
    private function authoredCandidates(Learner $learner, Course $course, MaterialScope $lessonScope, ?MaterialScope $stageScope, LocaleSet $locales, string $seed, int $max): array
    {
        /** @var array<int, array{0: PracticeItemType, 1: PracticeItemVersion}> $byItem */
        $byItem = [];
        foreach ([$lessonScope, $stageScope] as $scope) {
            if ($scope === null) {
                continue;
            }
            foreach (PracticeItemType::cases() as $type) {
                foreach ($this->material->eligibleItems($scope, $type, $learner) as $version) {
                    $byItem[$version->item_id] ??= [$type, $version];
                }
            }
        }
        if ($byItem === []) {
            return [];
        }

        $chosen = [];
        $dictations = 0;
        foreach (SeededShuffle::apply(array_values($byItem), $seed, 'authored') as [$type, $version]) {
            if ($type === PracticeItemType::Dictation) {
                if ($dictations >= 1) {
                    continue;
                }
                $dictations++;
            }
            $chosen[$type->value][] = $version;
            if (count($chosen, COUNT_RECURSIVE) - count($chosen) >= $max) {
                break;
            }
        }

        $built = $this->authored->buildAuthored($course, $learner, $locales, $seed, $chosen);
        $order = [];
        foreach ($chosen as $versions) {
            foreach ($versions as $version) {
                $order[$version->item_id] = count($order);
            }
        }
        usort($built, static fn (AuthoredCandidate $a, AuthoredCandidate $b): int => ($order[$a->itemId] ?? 0) <=> ($order[$b->itemId] ?? 0));

        return $built;
    }

    /**
     * The distractors in one query (docs/18 §12.3.1): the course's words outside the pool (tier 1) and the words of the public courses
     * of the platform (tier 2), `distractor_pool` of each, with their translations.
     *
     * @param  list<MaterialWord>  $pool
     * @return list<RunPoolWord>
     */
    private function distractors(Course $course, array $pool, LocaleSet $locales): array
    {
        $limit = max(0, (int) config('game.run.distractor_pool', 40));
        if ($limit === 0) {
            return [];
        }
        $poolIds = array_map(static fn (MaterialWord $w): int => $w->id, $pool);

        $ownCourse = $this->distractorQuery()->where('stages.course_id', $course->id)->whereNotIn('words.id', $poolIds)->limit($limit);
        $public = $this->distractorQuery()
            ->whereIn('stages.course_id', Course::query()->publicPlatform()->where('courses.id', '<>', $course->id)->select('courses.id'))
            ->limit($limit);

        /** @var list<Word> $rows */
        $rows = $ownCourse->union($public)->get()->all();

        $byLocale = [];
        foreach ($locales->locales as $locale) {
            $byLocale[$locale] = $this->translations->map($rows, $locale);
        }

        $distractors = [];
        foreach ($rows as $row) {
            $translations = [];
            foreach ($byLocale as $locale => $texts) {
                $translations[$locale] = $texts[$row->id] ?? $row->translation;
            }
            $word = new MaterialWord($row->id, $row->lesson_id, $row->word, $row->translation, $row->audio_path, $translations, $row->example);
            $tier = (int) $row->getAttribute('course_id') === $course->id ? RunPoolWord::TIER_COURSE : RunPoolWord::TIER_PUBLIC;
            $distractors[] = $this->poolWord($word, $locales, $tier);
        }

        return $distractors;
    }

    /** @return Builder<Word> */
    private function distractorQuery(): Builder
    {
        return Word::query()
            ->select('words.id', 'words.lesson_id', 'words.word', 'words.translation', 'words.audio_path', 'words.example', 'stages.course_id')
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('words.is_active', true)
            ->orderBy('words.id');
    }

    /** The facts of a word the composer decides by (docs/18 §12.3.1). */
    private function poolWord(MaterialWord $word, LocaleSet $locales, int $tier): RunPoolWord
    {
        // The recording of the CURRENT text of the word only (the test the dictation makes): an older clip is not this word's.
        $path = $this->wordAudio->pathFor($word->word);
        $audio = $path !== null && $word->audioPath === $path ? $path : null;

        return new RunPoolWord($word, $audio, $this->gaps->fromText($word->word, $word->example), PairsMode::candidate($word, $locales), $tier);
    }
}
