<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Content\DTO\ResolvedTranslation;
use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Learning\Exceptions\ExamSessionException;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Word;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the CANONICAL set of multiple-choice questions for an exam session.
 *
 * A question = { word_id, word, correct_translation, options[] } where
 * `options` contains the correct translation plus up to 3 real distractors.
 * The canonical order of questions / options is irrelevant for participants:
 * {@see ExamQuestionPersonalizer} derives a per-learner permutation at
 * serving time, so no `correct_index` is stored.
 *
 * Texts are the translations in `$locale` (the locale of the group) with the
 * ru fallback, read through {@see WordTranslationResolver}.
 *
 * Distractors are REAL translations only — never synthetic placeholders,
 * which would give the answer away. A distractor is also preferably in the
 * SAME language as the correct answer: a Cyrillic ru-fallback option next to
 * a Latin uz answer (or vice versa) would give it away. The candidate pool
 * is layered, and only ACTIVE words take part (as questions and as distractors):
 *   1. words of other lessons in the same stage;
 *   2. words of other stages of the SAME course;
 *   3. other words of the same lesson;
 *   4. words of other courses — a whitelist ({@see Course::scopePublicPlatform()}):
 *      only `published` + `public` courses of the platform (`owner_type =
 *      platform`); draft / review / archived / unlisted / private / organization
 *      courses never leak in.
 *      This layer is loaded only when the own course has fewer unique
 *      candidates IN THE ANSWER LANGUAGE (`$locale`) than needed — a course
 *      full of ru-only words does not stop a uz group from borrowing uz words.
 * Candidates in the language of the correct answer are exhausted across all
 * layers before candidates in another language are touched (last resort, so
 * the exam still works for a barely translated course) — so a foreign
 * published course in the answer's language still beats an own-course word
 * in the other language. Inside every layer, candidates sharing the word's
 * `part_of_speech` are preferred. Candidates are de-duplicated
 * case/whitespace-insensitively and can never collide with the correct translation.
 *
 * If a word still has fewer than 3 distractors, the question is emitted
 * with fewer options (but at least MIN_OPTIONS_PER_QUESTION). A word with no
 * distractor at all makes the exam impossible — an exception is thrown so the
 * teacher gets a clear message instead of a broken exam.
 *
 * The returned array is persisted verbatim into `exam_sessions.config.questions`.
 *
 * {@see self::forWords()} builds the same questions for an explicit word list of a
 * COURSE (personal mocks, docs/13 §8.4): the layers are then the other active
 * words of the course, the listed words themselves, and the public platform
 * courses as the last resort.
 */
final class ExamQuestionBuilder
{
    public const OPTIONS_PER_QUESTION = 4;

    public const MIN_OPTIONS_PER_QUESTION = 2;

    public const MIN_QUESTIONS = 4;

    public const MAX_QUESTIONS = 30;

    public function __construct(private readonly WordTranslationResolver $translations) {}

    /**
     * @return list<array{
     *   word_id: int,
     *   word: string,
     *   correct_translation: string,
     *   options: list<string>
     * }>
     *
     * @throws ExamSessionException when some word has no real distractor at all
     */
    public function build(Lesson $lesson, int $totalQuestions, string $locale): array
    {
        $totalQuestions = max(self::MIN_QUESTIONS, min(self::MAX_QUESTIONS, $totalQuestions));

        /** @var list<Word> $words */
        $words = Word::query()
            ->where('lesson_id', $lesson->id)
            ->where('is_active', true)
            ->inRandomOrder()
            ->limit($totalQuestions)
            ->get()
            ->all();

        if (count($words) < self::MIN_QUESTIONS) {
            return [];
        }

        $layers = $this->loadDistractorLayers($lesson, count($words), $locale);
        $correct = $this->translations->resolveMany($words, $locale);

        $questions = [];
        foreach ($words as $word) {
            $questions[] = $this->buildQuestion($word, $correct[(int) $word->id], $layers);
        }

        return $questions;
    }

    /**
     * Questions for the given active words of `$course`, in the given order (the caller
     * decides the sampling — e.g. a seeded shuffle). Words of other courses are refused.
     *
     * @param  list<Word>  $words
     * @return list<array{
     *   word_id: int,
     *   word: string,
     *   correct_translation: string,
     *   options: list<string>
     * }>
     *
     * @throws ExamSessionException when some word has no real distractor at all
     */
    public function forWords(array $words, Course $course, string $locale): array
    {
        if ($words === []) {
            return [];
        }

        $ids = array_map(static fn (Word $word): int => (int) $word->id, $words);
        $layers = $this->loadCourseLayers($course, $ids, $locale);
        $correct = $this->translations->resolveMany($words, $locale);

        $questions = [];
        foreach ($words as $word) {
            $questions[] = $this->buildQuestion($word, $correct[(int) $word->id], $layers);
        }

        return $questions;
    }

    /**
     * @param  list<list<array{translation:string, part_of_speech:string|null, locale:string}>>  $layers
     * @return array{word_id:int, word:string, correct_translation:string, options:list<string>}
     *
     * @throws ExamSessionException
     */
    private function buildQuestion(Word $word, ResolvedTranslation $resolved, array $layers): array
    {
        $correct = $resolved->text;

        $distractors = $this->pickDistractors(
            $layers,
            self::OPTIONS_PER_QUESTION - 1,
            $correct,
            $word->part_of_speech,
            $resolved->locale,
        );

        if (count($distractors) < self::MIN_OPTIONS_PER_QUESTION - 1) {
            throw ExamSessionException::notEnoughDistractors($word->word);
        }

        $options = array_merge([$correct], $distractors);
        shuffle($options);

        return [
            'word_id' => (int) $word->id,
            'word' => $word->word,
            'correct_translation' => $correct,
            'options' => $options,
        ];
    }

    /**
     * Loads candidate pools, most preferred layer first.
     *
     * @return list<list<array{translation:string, part_of_speech:string|null, locale:string}>>
     */
    private function loadDistractorLayers(Lesson $lesson, int $wordCount, string $locale): array
    {
        // Enough variety to avoid repeating the same distractors across the exam.
        $needed = max(40, $wordCount * 6);

        $sameStage = $this->candidates(
            Word::query()
                ->where('is_active', true)
                ->where('lesson_id', '!=', $lesson->id)
                ->whereHas('lesson', fn ($q) => $q->where('stage_id', $lesson->stage_id)),
            $needed,
            $locale,
        );

        $courseId = $lesson->stage()->value('course_id');

        $sameCourse = $this->uniqueInLocale($sameStage, $locale) >= $needed
            ? []
            : $this->candidates(
                Word::query()
                    ->where('is_active', true)
                    ->whereHas('lesson.stage', fn ($q) => $q
                        ->where('course_id', $courseId)
                        ->where('id', '!=', $lesson->stage_id)),
                $needed,
                $locale,
            );

        $sameLesson = $this->candidates(
            Word::query()->where('is_active', true)->where('lesson_id', $lesson->id),
            self::MAX_QUESTIONS,
            $locale,
        );

        // Last resort: public published courses of the platform, only when the own course cannot supply
        // enough unique candidates in the answer language.
        $foreign = $this->uniqueInLocale(array_merge($sameStage, $sameCourse, $sameLesson), $locale) >= $needed
            ? []
            : $this->candidates($this->foreignWords((int) $courseId), $needed, $locale);

        return [$sameStage, $sameCourse, $sameLesson, $foreign];
    }

    /**
     * Candidate pools for a word list of a course: other active words of the course first,
     * the listed words themselves next, public platform courses only when the course cannot
     * supply enough unique candidates in the answer language.
     *
     * @param  list<int>  $wordIds
     * @return list<list<array{translation:string, part_of_speech:string|null, locale:string}>>
     */
    private function loadCourseLayers(Course $course, array $wordIds, string $locale): array
    {
        $needed = max(40, count($wordIds) * 6);

        $inCourse = fn (): Builder => Word::query()
            ->where('is_active', true)
            ->whereHas('lesson.stage', fn ($q) => $q->where('course_id', $course->id));

        $otherWords = $this->candidates($inCourse()->whereNotIn('id', $wordIds), $needed, $locale);
        $ownWords = $this->candidates($inCourse()->whereIn('id', $wordIds), count($wordIds), $locale);

        $foreign = $this->uniqueInLocale(array_merge($otherWords, $ownWords), $locale) >= $needed
            ? []
            : $this->candidates($this->foreignWords($course->id), $needed, $locale);

        return [$otherWords, $ownWords, $foreign];
    }

    /**
     * Active words of the foreign courses a distractor may come from ({@see Course::scopePublicPlatform()}),
     * `$exceptCourseId` itself excluded.
     *
     * @return Builder<Word>
     */
    private function foreignWords(int $exceptCourseId): Builder
    {
        return Word::query()
            ->where('is_active', true)
            ->whereHas('lesson.stage.course', function (Builder $courses) use ($exceptCourseId): void {
                /** @var Builder<Course> $courses */
                $courses->where('courses.id', '!=', $exceptCourseId)->publicPlatform();
            });
    }

    /**
     * Number of distinct (case/whitespace-insensitive) candidate texts written in `$locale`.
     *
     * @param  list<array{translation:string, part_of_speech:string|null, locale:string}>  $candidates
     */
    private function uniqueInLocale(array $candidates, string $locale): int
    {
        $unique = [];
        foreach ($candidates as $c) {
            if ($c['locale'] === $locale) {
                $unique[$this->normalize($c['translation'])] = true;
            }
        }

        return count($unique);
    }

    /**
     * @param  Builder<Word>  $query
     * @return list<array{translation:string, part_of_speech:string|null, locale:string}>
     */
    private function candidates(Builder $query, int $limit, string $locale): array
    {
        $rows = $query
            ->inRandomOrder()
            ->limit($limit)
            ->get(['id', 'translation', 'part_of_speech']);

        $resolved = $this->translations->resolveMany($rows, $locale);

        $out = [];
        foreach ($rows as $row) {
            $translation = $resolved[(int) $row->id];
            $out[] = [
                'translation' => $translation->text,
                'part_of_speech' => $row->part_of_speech !== null ? (string) $row->part_of_speech : null,
                'locale' => $translation->locale,
            ];
        }

        return $out;
    }

    /**
     * Picks up to $count distinct real distractors: first the candidates in the language of
     * the correct answer (`$locale`), then — only if there are not enough — the others. Inside
     * a language, layers are walked in order, preferring the same part of speech in each layer.
     *
     * @param  list<list<array{translation:string, part_of_speech:string|null, locale:string}>>  $layers
     * @return list<string>
     */
    private function pickDistractors(array $layers, int $count, string $correct, ?string $partOfSpeech, string $locale): array
    {
        $seen = [$this->normalize($correct) => true];
        $picked = [];

        foreach ([true, false] as $sameLanguage) {
            foreach ($layers as $layer) {
                $inLanguage = array_values(array_filter(
                    $layer,
                    fn (array $c): bool => ($c['locale'] === $locale) === $sameLanguage,
                ));

                foreach ($this->byPreference($inLanguage, $partOfSpeech) as $bucket) {
                    shuffle($bucket);
                    foreach ($bucket as $candidate) {
                        if (count($picked) >= $count) {
                            return $picked;
                        }

                        $key = $this->normalize($candidate['translation']);
                        if ($key === '' || isset($seen[$key])) {
                            continue;
                        }

                        $seen[$key] = true;
                        $picked[] = $candidate['translation'];
                    }
                }
            }
        }

        return $picked;
    }

    /**
     * Splits a layer into [same part of speech, the rest]. When the target
     * word has no part of speech, everything goes into a single bucket.
     *
     * @param  list<array{translation:string, part_of_speech:string|null, locale:string}>  $layer
     * @return list<list<array{translation:string, part_of_speech:string|null, locale:string}>>
     */
    private function byPreference(array $layer, ?string $partOfSpeech): array
    {
        if ($partOfSpeech === null || $partOfSpeech === '') {
            return [$layer];
        }

        $same = [];
        $other = [];
        foreach ($layer as $candidate) {
            if ($candidate['part_of_speech'] !== null && strcasecmp($candidate['part_of_speech'], $partOfSpeech) === 0) {
                $same[] = $candidate;
            } else {
                $other[] = $candidate;
            }
        }

        return [$same, $other];
    }

    private function normalize(string $translation): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($translation));

        return mb_strtolower($collapsed ?? trim($translation));
    }
}
