<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExamAnswer;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\Word;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExamAnswer> */
class ExamAnswerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $isCorrect = fake()->boolean(70);

        return [
            'exam_session_id' => ExamSession::factory(),
            'student_id' => Student::factory(),
            'word_id' => Word::factory(),
            'selected_translation' => fake()->word(),
            'is_correct' => $isCorrect,
            'score' => $isCorrect ? fake()->numberBetween(1, 10) : 0,
            'time_spent_ms' => fake()->numberBetween(800, 12_000),
            'answered_at' => now(),
        ];
    }
}
