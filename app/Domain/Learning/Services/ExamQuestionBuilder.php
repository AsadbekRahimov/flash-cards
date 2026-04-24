<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Models\Lesson;
use App\Models\Word;

/**
 * Builds a fixed set of multiple-choice questions for an exam session.
 *
 * A question = { word_id, word, correct_translation, options[4] }
 * where `options` always contains the correct translation plus 3 distractors
 * randomly picked from other lessons (same or any stage). The position of the
 * correct option is randomized per question.
 *
 * The returned array is meant to be persisted verbatim into
 * `exam_sessions.config.questions` so every student sees the same sequence.
 */
final class ExamQuestionBuilder
{
    public const OPTIONS_PER_QUESTION = 4;

    public const MIN_QUESTIONS = 4;

    public const MAX_QUESTIONS = 30;

    /**
     * @return array<int, array{
     *   word_id: int,
     *   word: string,
     *   correct_translation: string,
     *   correct_index: int,
     *   options: list<string>
     * }>
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

        $distractorPool = $this->loadDistractorPool($lesson, count($words));

        $questions = [];
        foreach ($words as $word) {
            $questions[] = $this->buildQuestion($word, $distractorPool);
        }

        return $questions;
    }

    /**
     * @param  list<string>  $distractorPool
     * @return array{
     *   word_id: int,
     *   word: string,
     *   correct_translation: string,
     *   correct_index: int,
     *   options: list<string>
     * }
     */
    private function buildQuestion(Word $word, array $distractorPool): array
    {
        $correct = $word->translation;

        $distractors = $this->pickDistinctDistractors(
            $distractorPool,
            self::OPTIONS_PER_QUESTION - 1,
            $correct,
        );

        $options = array_merge([$correct], $distractors);
        shuffle($options);

        $correctIndex = array_search($correct, $options, true);
        if ($correctIndex === false) {
            $correctIndex = 0;
            $options[0] = $correct;
        }

        return [
            'word_id' => $word->id,
            'word' => $word->word,
            'correct_translation' => $correct,
            'correct_index' => (int) $correctIndex,
            'options' => array_values($options),
        ];
    }

    /**
     * @return list<string>
     */
    private function loadDistractorPool(Lesson $lesson, int $wordCount): array
    {
        // Prefer distractors from the SAME stage (stylistic consistency),
        // falling back to any other lesson if the stage has too few words.
        $needed = max(12, $wordCount * 3);

        /** @var list<string> $sameStage */
        $sameStage = Word::query()
            ->where('lesson_id', '!=', $lesson->id)
            ->whereHas('lesson', fn ($q) => $q->where('stage_id', $lesson->stage_id))
            ->inRandomOrder()
            ->limit($needed)
            ->pluck('translation')
            ->all();

        if (count($sameStage) >= $needed) {
            return array_values(array_unique($sameStage));
        }

        /** @var list<string> $anywhere */
        $anywhere = Word::query()
            ->where('lesson_id', '!=', $lesson->id)
            ->inRandomOrder()
            ->limit($needed)
            ->pluck('translation')
            ->all();

        return array_values(array_unique(array_merge($sameStage, $anywhere)));
    }

    /**
     * @param  list<string>  $pool
     * @return list<string>
     */
    private function pickDistinctDistractors(array $pool, int $count, string $exclude): array
    {
        $unique = array_values(array_unique(array_filter(
            $pool,
            static fn (string $t): bool => $t !== $exclude,
        )));

        shuffle($unique);

        $picked = array_slice($unique, 0, $count);

        // Guarantee we always return exactly $count items — even if the pool
        // is too small, by padding with synthetic placeholders. This avoids
        // leaking back the correct answer as a duplicate option.
        for ($i = count($picked); $i < $count; $i++) {
            $picked[] = "— (filler #{$i})";
        }

        return $picked;
    }
}
