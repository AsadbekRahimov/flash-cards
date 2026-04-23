<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExamResult> */
class ExamResultFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $total = 10;
        $correct = fake()->numberBetween(3, $total);

        return [
            'exam_session_id' => ExamSession::factory(),
            'student_id' => Student::factory(),
            'total_score' => $correct * 10,
            'correct_count' => $correct,
            'total_count' => $total,
            'rank' => fake()->numberBetween(1, 5),
        ];
    }
}
