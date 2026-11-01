<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\Enums\FinishReason;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\SessionStatus;
use App\Models\Course;
use App\Models\Learner;
use App\Models\PracticeSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A solo session as a row (docs/16 §2.5): by default an ACTIVE one with an empty snapshot and the state of a new session.
 * The snapshot has no elements, so it is for the tests of the schema, the scheduler and the isolation; a session that is
 * played comes from `PracticeSessionService::open`.
 *
 * @extends Factory<PracticeSession>
 */
class PracticeSessionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'active_learner_id' => fn (array $attributes): ?int => $attributes['status'] === SessionStatus::Active ? $attributes['learner_id'] : null,
            'mode' => PracticeMode::Pairs,
            'course_id' => Course::factory()->published(),
            'lesson_id' => null,
            'source' => PracticeSession::SOURCE_HUB,
            'retry_of_session_id' => null,
            'status' => SessionStatus::Active,
            'finish_reason' => null,
            'snapshot' => (new Snapshot(PracticeMode::Pairs, ['ru'], [], [], 'course', ['ru' => 'Курс']))->toArray(),
            'state' => ['current' => 0, 'elements' => [], 'pairs' => [], 'replacements' => 0, 'replaced' => []],
            'total' => 3,
            'answered' => 0,
            'correct_first' => 0,
            'hints' => 0,
            'mismatches' => 0,
            'locale' => 'ru',
            'expires_at' => now()->addHours(24),
            'started_at' => now(),
            'finished_at' => null,
        ];
    }

    public function ofMode(PracticeMode $mode): static
    {
        return $this->state(fn (array $attributes): array => [
            'mode' => $mode,
            'snapshot' => (new Snapshot($mode, ['ru'], [], [], 'course', ['ru' => 'Курс']))->toArray(),
        ]);
    }

    /** Past its `expires_at` and still active: the scheduler has not seen it yet. */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'started_at' => now()->subHours(25),
            'expires_at' => now()->subHour(),
        ]);
    }

    public function finished(FinishReason $reason = FinishReason::Completed): static
    {
        return $this->state(fn (): array => [
            'status' => SessionStatus::Finished,
            'active_learner_id' => null,
            'finish_reason' => $reason,
            'finished_at' => now(),
        ]);
    }
}
