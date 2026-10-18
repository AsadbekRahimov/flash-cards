<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Learning\Services\ExamQuestionPersonalizer;
use App\Models\ExamSession;
use App\Models\Lesson;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExamSession> */
class ExamSessionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'telegram_group_id' => TelegramGroup::factory(),
            'lesson_id' => Lesson::factory(),
            'started_by_user_id' => User::factory(),
            'status' => 'open',
            'started_at' => now(),
            'ends_at' => now()->addHour(),
            'closed_at' => null,
            'leaderboard_posted_at' => null,
            'config' => self::config(self::placeholderQuestions(4)),
        ];
    }

    /**
     * Gives the session `$n` answerable questions in the canonical
     * `config.questions` format (what `ExamQuestionBuilder` stores): real
     * `words` rows are created in the session's lesson, `word_id` points at
     * them, and `options` holds the correct translation plus three distractors.
     * Runs on `make()`, so it touches the database even without `create()`.
     */
    public function withQuestions(int $n): static
    {
        return $this->afterMaking(function (ExamSession $session) use ($n): void {
            $questions = [];
            foreach (Word::factory()->count($n)->create(['lesson_id' => $session->lesson_id]) as $i => $word) {
                $questions[] = self::question((int) $word->id, $word->word, $word->translation, $i);
            }

            $session->config = array_merge($session->config ?? [], self::config($questions));
        });
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'closed',
            'closed_at' => now(),
        ]);
    }

    /**
     * Default questions are shape-only: `word_id`s are not backed by `words`
     * rows. Use {@see self::withQuestions()} when the exam is actually played.
     *
     * @return list<array{word_id:int, word:string, correct_translation:string, options:list<string>}>
     */
    private static function placeholderQuestions(int $n): array
    {
        $questions = [];
        for ($i = 0; $i < $n; $i++) {
            $questions[] = self::question($i + 1, "word_{$i}", "translation_{$i}", $i);
        }

        return $questions;
    }

    /**
     * @return array{word_id:int, word:string, correct_translation:string, options:list<string>}
     */
    private static function question(int $wordId, string $word, string $translation, int $i): array
    {
        return [
            'word_id' => $wordId,
            'word' => $word,
            'correct_translation' => $translation,
            'options' => [$translation, "distractor_{$i}_a", "distractor_{$i}_b", "distractor_{$i}_c"],
        ];
    }

    /**
     * @param  list<array{word_id:int, word:string, correct_translation:string, options:list<string>}>  $questions
     * @return array<string, mixed>
     */
    private static function config(array $questions): array
    {
        return [
            'total_questions' => count($questions),
            'seconds_per_question' => 10,
            'duration_minutes' => 60,
            'question_order' => ExamQuestionPersonalizer::ORDER_PER_STUDENT,
            'questions' => $questions,
        ];
    }
}
