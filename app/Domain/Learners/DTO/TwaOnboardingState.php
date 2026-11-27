<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

/**
 * What the onboarding of the Mini App shows (docs/40 §2.1, docs/04 A.24): whether it is done, the screen to resume on, the values
 * saved so far (each step writes its column at once) and the choices of every screen. The labels of the choices are the client's
 * (i18n of the Mini App); the course titles are the catalog's, in the learner's language.
 *
 * @phpstan-type LevelOption array{level: string, levels: list<string>, course_slug: string, title: string, level_label: string, stages_count: int|null}
 * @phpstan-type ChosenCourse array{slug: string, title: string, level_label: string, stages_count: int|null}
 * @phpstan-type StateValues array{
 *   display_name: string|null,
 *   first_name: string|null,
 *   age_range: string|null,
 *   goals: list<string>,
 *   level: string|null,
 *   course: ChosenCourse|null,
 *   daily_goal: int,
 *   reminder: array{enabled: bool, time: string|null}
 * }
 * @phpstan-type StateOptions array{
 *   age_ranges: list<string>,
 *   goals: list<string>,
 *   levels: list<LevelOption>,
 *   daily_goals: list<int>,
 *   daily_goal_max: int|null,
 *   recommended_daily_goal: int,
 *   reminder_times: list<string>,
 *   reminder_step_minutes: int
 * }
 */
final readonly class TwaOnboardingState
{
    /**
     * @param  StateValues  $values
     * @param  StateOptions  $options
     */
    public function __construct(
        public bool $completed,
        public ?string $step,
        public array $values,
        public array $options,
    ) {}

    /** @return array{completed: bool, step: string|null, values: StateValues, options: StateOptions} */
    public function toArray(): array
    {
        return [
            'completed' => $this->completed,
            'step' => $this->step,
            'values' => $this->values,
            'options' => $this->options,
        ];
    }
}
