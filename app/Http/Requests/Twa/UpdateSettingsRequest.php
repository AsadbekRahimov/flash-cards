<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Learners\Services\LearnerSettingsService;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Localization\Services\LocaleResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/twa/me/settings — a partial update: only the fields present are validated and changed.
 * Access is the `TwaAuth` middleware; the same rules are enforced again by LearnerSettingsService.
 */
final class UpdateSettingsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $settings = $this->container->make(LearnerSettingsService::class);

        return [
            'interface_locale' => ['sometimes', 'string', Rule::in($this->container->make(LocaleResolver::class)->supported())],
            'timezone' => ['sometimes', 'string', 'max:64', Rule::in($settings->knownTimezones())],
            // `null` turns the reminders off; otherwise HH:MM on a quarter of an hour.
            'reminder_time' => ['sometimes', 'nullable', 'string', 'regex:/^([01]\d|2[0-3]):(00|15|30|45)$/'],
            'daily_goal' => ['sometimes', 'integer:strict', Rule::in($settings->dailyGoals())],
            'goal' => ['sometimes', 'string', Rule::in(OnboardingService::GOALS)],
            'level' => ['sometimes', 'string', Rule::in(OnboardingService::LEVELS)],
        ];
    }
}
