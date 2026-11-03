<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Enums\RunStatus;
use App\Models\ClassCaravan;
use App\Models\Course;
use App\Models\GameRun;
use App\Models\Learner;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A run as a row (docs/18 §12.2.1): by default an ACTIVE `stop` run with an empty snapshot and the state of a new run. The snapshot
 * has no elements, so it is for the tests of the schema, the scheduler and the isolation; a run that is played comes from
 * `RunService::start` (G2.2a).
 *
 * @extends Factory<GameRun>
 */
class GameRunFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'active_learner_id' => fn (array $attributes): ?int => $attributes['status'] === RunStatus::Active ? $attributes['learner_id'] : null,
            'kind' => RunKind::Stop,
            'course_id' => Course::factory()->published(),
            'stage_id' => null,
            'lesson_id' => null,
            'class_caravan_id' => null,
            'daily_key' => null,
            'class_boss_key' => null,
            'local_date' => now()->toDateString(),
            'status' => RunStatus::Active,
            'finish_reason' => null,
            'free_slot' => null,
            'snapshot' => ['version' => 1, 'elements' => []],
            'state' => ['current' => 0, 'elements' => []],
            'total' => 8,
            'answered' => 0,
            'correct' => 0,
            'typos' => 0,
            'wrong' => 0,
            'hints' => 0,
            'flasks' => 3,
            'flasks_max' => 3,
            'combo_best' => 0,
            'score' => 0,
            'stars' => null,
            'coins_awarded' => 0,
            'league_points' => 0,
            'srs_pulled' => 0,
            'locale' => 'ru',
            'expires_at' => now()->addMinutes(30),
            'started_at' => now(),
            'finished_at' => null,
        ];
    }

    /** A stop: the run of a lesson (its course and stage come from the lesson). */
    public function forLesson(Lesson $lesson): static
    {
        return $this->state(fn (): array => [
            'kind' => RunKind::Stop,
            'course_id' => (int) $lesson->stage()->value('course_id'),
            'stage_id' => $lesson->stage_id,
            'lesson_id' => $lesson->id,
        ]);
    }

    /** The daily caravan: `daily_key` is "{learner_id}:{local_date}". */
    public function daily(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => RunKind::Daily,
            'daily_key' => fn (array $resolved): string => $resolved['learner_id'].':'.$resolved['local_date'],
        ]);
    }

    /** A boss: no flasks (`flasks_max = 0`). */
    public function boss(): static
    {
        return $this->state(fn (): array => ['kind' => RunKind::Boss, 'flasks' => 0, 'flasks_max' => 0, 'total' => 12]);
    }

    /** The boss of a class: bound to its caravan, `class_boss_key` is "{class_caravan_id}:{learner_id}". */
    public function classBoss(ClassCaravan $caravan): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => RunKind::ClassBoss,
            'flasks' => 0,
            'flasks_max' => 0,
            'total' => 12,
            'class_caravan_id' => $caravan->id,
            'class_boss_key' => fn (array $resolved): string => $caravan->id.':'.$resolved['learner_id'],
        ]);
    }

    /** Past its `expires_at` and still active: the scheduler has not seen it yet. */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'started_at' => now()->subMinutes(31),
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function finished(RunFinishReason $reason = RunFinishReason::Completed): static
    {
        return $this->state(fn (): array => [
            'status' => RunStatus::Finished,
            'active_learner_id' => null,
            'finish_reason' => $reason,
            'finished_at' => now(),
        ]);
    }
}
