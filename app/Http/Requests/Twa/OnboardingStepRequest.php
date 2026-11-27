<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Learners\Services\LearnerSettingsService;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Learners\Support\OnboardingChoices;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/twa/onboarding/steps/{step} (docs/40 §2.1, docs/04 A.24) - the body of one step of the onboarding of the Mini App. Strict
 * types, no coercion; an unknown `{step}` never gets here (404 by the route). The whitelists are checked again by TwaOnboardingService,
 * and the rules of a name (DisplayName: 422 `display_name_invalid {reason}`) and of the course of a level are its own.
 *
 *   name        {display_name: string}
 *   age         {age_range: lt14|14_17|18_24|25_34|35_44|45p}
 *   goals       {goals: string[]}  1-6 of OnboardingChoices::GOALS, no repeats
 *   level       {level: A1..C2, course_slug: string}
 *   daily_goal  {daily_goal: int}  one of `learners.daily_goal_options`
 *   reminder    {enabled: bool, time: 'HH:MM' on a quarter of an hour | null, write_access: granted|denied|not_asked}
 */
final class OnboardingStepRequest extends FormRequest
{
    public function step(): string
    {
        return (string) $this->route('step');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return match ($this->step()) {
            // `null` (an empty or blank string) is passed on: the service answers `display_name_invalid {reason: empty}`.
            'name' => ['display_name' => ['present', 'nullable', 'string', 'max:256']],
            'age' => ['age_range' => ['required', 'string', Rule::in(OnboardingChoices::AGE_RANGES)]],
            'goals' => [
                'goals' => ['required', 'list', 'min:1', 'max:'.count(OnboardingChoices::GOALS)],
                'goals.*' => ['required', 'string', 'distinct:strict', Rule::in(OnboardingChoices::GOALS)],
            ],
            'level' => [
                'level' => ['required', 'string', Rule::in(OnboardingService::LEVELS)],
                'course_slug' => ['required', 'string', 'max:64'],
            ],
            'daily_goal' => ['daily_goal' => ['required', 'integer:strict', Rule::in($this->container->make(LearnerSettingsService::class)->dailyGoals())]],
            'reminder' => [
                'enabled' => ['required', 'boolean:strict'],
                'time' => [Rule::requiredIf(fn (): bool => $this->input('enabled') === true), 'nullable', 'string', 'regex:/^([01]\d|2[0-3]):(00|15|30|45)$/'],
                'write_access' => ['required', 'string', Rule::in(OnboardingChoices::WRITE_ACCESS)],
            ],
            default => [],
        };
    }

    /** @return array<string, mixed> the validated body of the step */
    public function body(): array
    {
        /** @var array<string, mixed> */
        return $this->validated();
    }
}
