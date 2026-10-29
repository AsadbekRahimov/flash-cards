<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Practice\Enums\Verdict;
use App\Models\PracticeAttempt;
use App\Models\PracticeSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PracticeAttempt> */
class PracticeAttemptFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'session_id' => PracticeSession::factory(),
            'learner_id' => fn (array $attributes): int => (int) PracticeSession::query()->whereKey($attributes['session_id'])->value('learner_id'),
            'request_id' => (string) Str::uuid(),
            'element_id' => 'e'.Str::random(10),
            'kind' => PracticeAttempt::KIND_ANSWER,
            'payload' => ['text' => 'answer'],
            'verdict' => Verdict::Correct,
            'is_first' => true,
            'result' => ['verdict' => 'correct', 'first_attempt' => true, 'reveal' => null, 'pair' => null],
            'time_spent_ms' => null,
            'accepted_at' => now(),
        ];
    }
}
