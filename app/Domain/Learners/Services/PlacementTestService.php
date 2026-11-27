<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Content\DTO\CatalogCourse;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learners\DTO\PlacementQuestions;
use App\Domain\Learners\DTO\PlacementResult;
use App\Domain\Learners\Exceptions\InvalidSettingException;
use App\Domain\Learners\Exceptions\TwaOnboardingException;
use App\Domain\Learners\Support\OnboardingChoices;
use App\Domain\Learning\Services\ExamQuestionBuilder;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Take the 2-minute test" on the level screen of the onboarding of the Mini App (docs/40 §2.1a, docs/04 A.27): ten questions
 * "word → four translations", two active words of the course of each level A1, A2, B1, B2, C1 (a level without a course of its own
 * in the catalog is not asked), and from the answers the level and the course the level screen offers for it. The learner is a
 * newcomer, so nothing here is a repetition: no `word_repetitions`, no `training_sessions`, no `exam_sessions`, no points, no
 * streak - the test is a draft in `learners.meta.twa_onboarding.placement` and nothing else.
 *
 * - **Questions.** Per course of a tested level ONE statement: `2 × levels of the course + POOL` random active words of the course
 *   (`lessons` of its `stages`, `ORDER BY random()`); the first two per level are asked, the rest is the pool of distractors of the
 *   same course - {@see ExamQuestionBuilder::forWordsFromPool()} applies the rules of the exam (real translations, the language of
 *   the answer first, never a second right answer) without a statement of its own. The translations of every word of the test go in
 *   one batch (one statement cold, none warm).
 * - **The key stays on the server.** The client gets `{set_id, questions: [{id, word, options}], expires_at}`; the draft keeps
 *   `{set_id, items: [{id, word_id, level, correct, n}], …}` under the learner's row lock (the same lock the steps of the onboarding
 *   take: a parallel step keeps its keys of `meta`, this keeps its). `set_id` - {@see SET_ID_BYTES} random bytes in hex - names the
 *   set: the draft runs out after {@see TTL_MINUTES}; a new start replaces it (a new set of words, a new `set_id`).
 * - **Answers** come once, for one set: `{set_id, answers}`, `answers[i]` is the option chosen for question `i + 1` (`0 … n − 1`,
 *   `null` - skipped). Under the lock the `set_id` must be the draft's - the answers of a replaced set (a second tab, a start that
 *   overtook the answer) are never scored against the new one: 410 `placement_expired`. The draft is scored, the key is dropped and
 *   the result kept in its place with the `set_id`, so a repeated request for the same set (a retry, a second tab) gets the SAME
 *   result and can neither re-score nor probe the key - together with `throttle:twa-session-create` that is the defence against
 *   guessing. The level: walking A1 → C1 over the levels asked, the last level with at least one right answer before the first
 *   level with none (a level with none stops the walk); no level passed - A1. `course_slug` - the course of that level, as on the
 *   level screen; the client puts both into the `level` step ("your level is about …, you can change it").
 */
final class PlacementTestService
{
    /** The levels asked, in order; C2 is never asked (its course is for learners who need no test). */
    public const LEVELS = ['A1', 'A2', 'B1', 'B2', 'C1'];

    public const WORDS_PER_LEVEL = 2;

    /** How long the draft stays answerable. */
    public const TTL_MINUTES = 20;

    /** `meta.twa_onboarding.placement`. */
    public const META_KEY = 'placement';

    /** The random bytes of a `set_id` (32 hex characters): unguessable, so the answers name the set they were given for. */
    public const SET_ID_BYTES = 16;

    /** Random words of a course read on top of the asked ones: the distractors of its questions. */
    private const POOL = 20;

    /** Fewer questions than this and the test says nothing: 422 `placement_unavailable`. */
    private const MIN_QUESTIONS = 4;

    public function __construct(
        private readonly CourseCatalogService $catalog,
        private readonly ExamQuestionBuilder $questions,
        private readonly LocaleResolver $locales,
    ) {}

    /** Whether the catalog can give a test: at least two levels with a course of their own that has enough words (0 statements, the cached catalog). */
    public function isAvailable(): bool
    {
        $levels = 0;
        foreach ($this->courses() as $course) {
            if ($course['course']->wordsCount >= self::WORDS_PER_LEVEL * count($course['levels'])) {
                $levels += count($course['levels']);
            }
        }

        return $levels >= 2;
    }

    /**
     * A new test for the learner: the questions, and the key written into the draft (any earlier draft or result is replaced).
     *
     * @throws TwaOnboardingException 409 `onboarding_completed`; 422 `placement_unavailable`
     */
    public function start(Learner $learner, ?CarbonImmutable $now = null): PlacementQuestions
    {
        if ($learner->onboarding_state === OnboardingService::DONE) {
            throw TwaOnboardingException::completed();
        }
        $now ??= CarbonImmutable::now();
        $locale = $this->locales->forLearner($learner);

        [$questions, $items] = $this->compose($locale);
        if (count($questions) < self::MIN_QUESTIONS) {
            throw TwaOnboardingException::placementUnavailable();
        }

        $expiresAt = $now->addMinutes(self::TTL_MINUTES);
        $setId = bin2hex(random_bytes(self::SET_ID_BYTES));
        $this->writeDraft($learner, [
            'set_id' => $setId,
            'items' => $items,
            'issued_at' => $now->utc()->toIso8601String(),
            'expires_at' => $expiresAt->utc()->toIso8601String(),
        ]);

        return new PlacementQuestions($setId, $questions, $expiresAt);
    }

    /**
     * Scores the answers of the learner's draft once; a repeated call for the same set returns the stored result.
     *
     * @param  string  $setId  the `set_id` the start gave: another one (a replaced set) is 410, whatever the draft holds now
     * @param  array<mixed>  $answers  one per question, in the order of the questions: the index of the option chosen, null - skipped
     *                                 (checked here again whatever the request validated)
     *
     * @throws TwaOnboardingException 409 `onboarding_completed`; 410 `placement_expired` (expired, never started, another set)
     * @throws InvalidSettingException `answers` do not match the questions (422)
     */
    public function answer(Learner $learner, string $setId, array $answers, ?CarbonImmutable $now = null): PlacementResult
    {
        if ($learner->onboarding_state === OnboardingService::DONE) {
            throw TwaOnboardingException::completed();
        }
        $now ??= CarbonImmutable::now();
        $result = null;

        DB::transaction(function () use ($learner, $setId, $answers, $now, &$result): void {
            $fresh = $this->lock($learner);
            if ($fresh->onboarding_state === OnboardingService::DONE) {
                throw TwaOnboardingException::completed();
            }

            $draft = $this->draft($fresh);
            // The answers are for the set the draft holds now, or for nothing: a set replaced by a new start is never scored against it.
            if (! is_string($draft['set_id'] ?? null) || ! hash_equals($draft['set_id'], $setId)) {
                throw TwaOnboardingException::placementExpired();
            }
            $stored = is_array($draft['result'] ?? null) ? PlacementResult::fromStored($draft['result']) : null;
            if ($stored !== null) {
                // Answered already: the same result, nothing written, nothing re-scored.
                $result = $stored;
                $learner->setRawAttributes($fresh->getAttributes(), true);

                return;
            }

            $items = $this->items($draft);
            $expiresAt = is_string($draft['expires_at'] ?? null) ? CarbonImmutable::parse($draft['expires_at']) : null;
            if ($items === [] || $expiresAt === null || $expiresAt->lessThanOrEqualTo($now)) {
                throw TwaOnboardingException::placementExpired();
            }

            $result = $this->score($items, $this->normalizeAnswers($answers, $items));

            $progress = OnboardingChoices::progress($fresh);
            $progress[self::META_KEY] = [
                'set_id' => $setId,
                'issued_at' => $draft['issued_at'] ?? null,
                'answered_at' => $now->utc()->toIso8601String(),
                'result' => $result->toArray(),
            ];
            $fresh->meta = [...($fresh->meta ?? []), OnboardingChoices::META_KEY => $progress];
            $fresh->save();
            $learner->setRawAttributes($fresh->getAttributes(), true);
        });

        /** @var PlacementResult $result */
        return $result;
    }

    /**
     * The questions of a test and the key of each: two words per tested level from the course of the level.
     *
     * @return array{0: list<array{id: int, word: string, options: list<string>}>, 1: list<array{id: int, word_id: int, level: string, correct: int, n: int}>}
     */
    private function compose(string $locale): array
    {
        $byLevel = [];
        $all = [];
        foreach ($this->courses() as $course) {
            $rows = $this->randomWords($course['course']->id, self::WORDS_PER_LEVEL * count($course['levels']) + self::POOL);
            $asked = [];
            foreach ($course['levels'] as $i => $level) {
                $slice = array_slice($rows, $i * self::WORDS_PER_LEVEL, self::WORDS_PER_LEVEL);
                foreach ($slice as $word) {
                    $asked[(int) $word->id] = $level;
                }
            }
            $byLevel[] = ['asked' => $asked, 'rows' => $rows];
            $all = [...$all, ...$rows];
        }

        // One batch of translations for the whole test (cached per word); the builder then reads them from the cache.
        if ($all !== []) {
            $this->questions->translationsOf($all, $locale);
        }

        $questions = [];
        $items = [];
        foreach ($byLevel as ['asked' => $asked, 'rows' => $rows]) {
            $words = array_values(array_filter($rows, static fn (Word $word): bool => isset($asked[(int) $word->id])));
            foreach ($this->questions->forWordsFromPool($words, $rows, $locale) as $question) {
                $correct = array_search($question['correct_translation'], $question['options'], true);
                if (! is_int($correct)) {
                    continue;
                }
                $id = count($questions) + 1;
                $questions[] = ['id' => $id, 'word' => $question['word'], 'options' => $question['options']];
                $items[] = ['id' => $id, 'word_id' => $question['word_id'], 'level' => $asked[$question['word_id']], 'correct' => $correct, 'n' => count($question['options'])];
            }
        }

        return [$questions, $items];
    }

    /**
     * The courses asked: the course of every level of {@see LEVELS} that has one of its own, the neighbour without one in its row
     * ("A1–A2") - the rows of the level screen ({@see CourseCatalogService::levelCourses()}). A level whose offer is a fallback of
     * another level is not asked: asking the words of A1 as "B1" says nothing.
     *
     * @return list<array{course: CatalogCourse, levels: non-empty-list<string>}>
     */
    private function courses(): array
    {
        return $this->catalog->levelCourses(OnboardingService::GENERAL_GOAL, self::LEVELS);
    }

    /**
     * `$count` random active words of the course: the asked ones first, the pool after them.
     *
     * @return list<Word>
     */
    private function randomWords(int $courseId, int $count): array
    {
        return array_values(Word::query()
            ->where('is_active', true)
            ->whereIn('lesson_id', Lesson::query()->select('lessons.id')->whereIn('lessons.stage_id', Stage::query()->select('stages.id')->where('stages.course_id', $courseId)))
            ->inRandomOrder()
            ->limit($count)
            ->get(['id', 'word', 'translation', 'part_of_speech'])
            ->all());
    }

    /**
     * The level and the course from the right answers per level.
     *
     * @param  list<array{id: int, word_id: int, level: string, correct: int, n: int}>  $items
     * @param  array<int, int|null>  $answers  by question id
     */
    private function score(array $items, array $answers): PlacementResult
    {
        $byLevel = [];
        foreach ($items as $item) {
            $byLevel[$item['level']] ??= 0;
            if (($answers[$item['id']] ?? null) === $item['correct']) {
                $byLevel[$item['level']]++;
            }
        }

        $passed = null;
        foreach (self::LEVELS as $level) {
            if (! array_key_exists($level, $byLevel)) {
                continue;
            }
            if ($byLevel[$level] < 1) {
                break;
            }
            $passed = $level;
        }
        $level = $passed ?? self::LEVELS[0];

        return new PlacementResult($level, $this->courseSlugFor($level), $byLevel);
    }

    /** The course the level screen offers for the level (the same catalog, the same rule as the `level` step). */
    private function courseSlugFor(string $level): string
    {
        $course = $this->catalog->recommendFromCatalog(OnboardingService::GENERAL_GOAL, $level, $this->catalog->published($this->locales->default()));

        return $course === null ? '' : $course->slug;
    }

    /**
     * The answers by question id: exactly one per question, an int inside the options of the question or null.
     *
     * @param  array<mixed>  $answers
     * @param  list<array{id: int, word_id: int, level: string, correct: int, n: int}>  $items
     * @return array<int, int|null>
     *
     * @throws InvalidSettingException
     */
    private function normalizeAnswers(array $answers, array $items): array
    {
        if (! array_is_list($answers) || count($answers) !== count($items)) {
            throw InvalidSettingException::invalid('answers');
        }

        $byId = [];
        foreach ($items as $i => $item) {
            $answer = $answers[$i] ?? null;
            if ($answer !== null && (! is_int($answer) || $answer < 0 || $answer >= $item['n'])) {
                throw InvalidSettingException::invalid('answers');
            }
            $byId[$item['id']] = $answer;
        }

        return $byId;
    }

    /**
     * The well-formed items of a draft; `[]` when there is no draft or it is not of the shape.
     *
     * @param  array<string, mixed>  $draft
     * @return list<array{id: int, word_id: int, level: string, correct: int, n: int}>
     */
    private function items(array $draft): array
    {
        $items = [];
        foreach (is_array($draft['items'] ?? null) ? $draft['items'] : [] as $item) {
            if (! is_array($item) || ! is_int($item['id'] ?? null) || ! is_int($item['word_id'] ?? null) || ! is_string($item['level'] ?? null)
                || ! is_int($item['correct'] ?? null) || ! is_int($item['n'] ?? null)) {
                return [];
            }
            $items[] = ['id' => $item['id'], 'word_id' => $item['word_id'], 'level' => $item['level'], 'correct' => $item['correct'], 'n' => $item['n']];
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function draft(Learner $learner): array
    {
        $draft = OnboardingChoices::progress($learner)[self::META_KEY] ?? null;

        return is_array($draft) ? $draft : [];
    }

    /**
     * Writes the draft under the learner's row lock (the steps of the onboarding rewrite `meta` under the same lock, so neither
     * loses the other's keys); a learner who became `done` meanwhile is refused.
     *
     * @param  array<string, mixed>  $draft
     *
     * @throws TwaOnboardingException 409 `onboarding_completed`
     */
    private function writeDraft(Learner $learner, array $draft): void
    {
        DB::transaction(function () use ($learner, $draft): void {
            $fresh = $this->lock($learner);
            if ($fresh->onboarding_state === OnboardingService::DONE) {
                throw TwaOnboardingException::completed();
            }

            $progress = OnboardingChoices::progress($fresh);
            $progress[self::META_KEY] = $draft;
            $fresh->meta = [...($fresh->meta ?? []), OnboardingChoices::META_KEY => $progress];
            $fresh->save();
            $learner->setRawAttributes($fresh->getAttributes(), true);
        });
    }

    private function lock(Learner $learner): Learner
    {
        $lock = Learner::query()->whereKey($learner->id);

        /** @var Learner */
        return (DB::connection()->getDriverName() === 'pgsql' ? $lock->lock('for no key update') : $lock->lockForUpdate())->firstOrFail();
    }
}
