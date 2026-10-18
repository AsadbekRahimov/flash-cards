<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Exceptions\ExamSessionException;
use App\Models\Lesson;
use App\Models\Word;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the CANONICAL set of multiple-choice questions for an exam session.
 *
 * A question = { word_id, word, correct_translation, options[] } where
 * `options` contains the correct translation plus up to 3 real distractors.
 * The canonical order of questions / options is irrelevant for participants:
 * {@see ExamQuestionPersonalizer} derives a per-student permutation at
 * serving time, so no `correct_index` is stored.
 *
 * Distractors are REAL translations only — never synthetic placeholders,
 * which would give the answer away. The candidate pool is layered:
 *   1. words of other lessons in the same stage;
 *   2. words of any other lesson;
 *   3. other words of the same lesson.
 * Inside every layer, candidates sharing the word's `part_of_speech` are
 * preferred. Candidates are de-duplicated case/whitespace-insensitively and
 * can never collide with the correct translation.
 *
 * If a word still has fewer than 3 distractors, the question is emitted
 * with fewer options (but at least MIN_OPTIONS_PER_QUESTION). A word with no
 * distractor at all makes the exam impossible — an exception is thrown so the
 * teacher gets a clear message instead of a broken exam.
 *
 * The returned array is persisted verbatim into `exam_sessions.config.questions`.
 */
final class ExamQuestionBuilder
{
    public const OPTIONS_PER_QUESTION = 4;

    public const MIN_OPTIONS_PER_QUESTION = 2;

    public const MIN_QUESTIONS = 4;

    public const MAX_QUESTIONS = 30;

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
    public function build(Lesson $lesson, int $totalQuestions): array
    {
        $totalQuestions = max(self::MIN_QUESTIONS, min(self::MAX_QUESTIONS, $totalQuestions));

        /** @var list<Word> $words */
        $words = Word::query()
            ->where('lesson_id', $lesson->id)
            ->inRandomOrder()
            ->limit($totalQuestions)
            ->get()
            ->all();

        if (count($words) < self::MIN_QUESTIONS) {
            return [];
        }

        $layers = $this->loadDistractorLayers($lesson, count($words));

        $questions = [];
        foreach ($words as $word) {
            $questions[] = $this->buildQuestion($word, $layers);
        }

        return $questions;
    }

    /**
     * @param  list<list<array{translation:string, part_of_speech:string|null}>>  $layers
     * @return array{word_id:int, word:string, correct_translation:string, options:list<string>}
     *
     * @throws ExamSessionException
     */
    private function buildQuestion(Word $word, array $layers): array
    {
        $correct = $word->translation;

        $distractors = $this->pickDistractors(
            $layers,
            self::OPTIONS_PER_QUESTION - 1,
            $correct,
            $word->part_of_speech,
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
     * @return list<list<array{translation:string, part_of_speech:string|null}>>
     */
    private function loadDistractorLayers(Lesson $lesson, int $wordCount): array
    {
        // Enough variety to avoid repeating the same distractors across the exam.
        $needed = max(40, $wordCount * 6);

        $sameStage = $this->candidates(
            Word::query()
                ->where('lesson_id', '!=', $lesson->id)
                ->whereHas('lesson', fn ($q) => $q->where('stage_id', $lesson->stage_id)),
            $needed,
        );

        $anywhere = count($sameStage) >= $needed
            ? []
            : $this->candidates(
                Word::query()
                    ->where('lesson_id', '!=', $lesson->id)
                    ->whereHas('lesson', fn ($q) => $q->where('stage_id', '!=', $lesson->stage_id)),
                $needed,
            );

        $sameLesson = $this->candidates(
            Word::query()->where('lesson_id', $lesson->id),
            self::MAX_QUESTIONS,
        );

        return [$sameStage, $anywhere, $sameLesson];
    }

    /**
     * @param  Builder<Word>  $query
     * @return list<array{translation:string, part_of_speech:string|null}>
     */
    private function candidates(Builder $query, int $limit): array
    {
        $rows = $query
            ->inRandomOrder()
            ->limit($limit)
            ->get(['translation', 'part_of_speech']);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'translation' => (string) $row->translation,
                'part_of_speech' => $row->part_of_speech !== null ? (string) $row->part_of_speech : null,
            ];
        }

        return $out;
    }

    /**
     * Picks up to $count distinct real distractors, walking layers in order and
     * preferring candidates with the same part of speech inside each layer.
     *
     * @param  list<list<array{translation:string, part_of_speech:string|null}>>  $layers
     * @return list<string>
     */
    private function pickDistractors(array $layers, int $count, string $correct, ?string $partOfSpeech): array
    {
        $seen = [$this->normalize($correct) => true];
        $picked = [];

        foreach ($layers as $layer) {
            foreach ($this->byPreference($layer, $partOfSpeech) as $bucket) {
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

        return $picked;
    }

    /**
     * Splits a layer into [same part of speech, the rest]. When the target
     * word has no part of speech, everything goes into a single bucket.
     *
     * @param  list<array{translation:string, part_of_speech:string|null}>  $layer
     * @return list<list<array{translation:string, part_of_speech:string|null}>>
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
