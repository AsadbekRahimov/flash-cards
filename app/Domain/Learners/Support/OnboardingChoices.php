<?php

declare(strict_types=1);

namespace App\Domain\Learners\Support;

use App\Domain\Learners\Services\OnboardingService;
use App\Models\Learner;

/**
 * The whitelists and the order of the onboarding of the Mini App (docs/40 §2.1). The bot's onboarding has its own
 * ({@see OnboardingService}); both end in {@see OnboardingService::completeExternally()} / `apply()` and write the same `done`.
 *
 * The progress of the Mini App lives in `learners.meta.twa_onboarding` ({@see META_KEY}):
 *   `step`        the next screen to open (one of {@see SCREENS}); every saved step moves it to the one after it;
 *   `started_at`  the first saved step (ISO 8601, UTC) - also the mark that `onboarding.started {channel: mini_app}` was recorded;
 *   `course_slug` the course chosen on the level step (shown on the summary; the course itself is set when the onboarding completes);
 *   `answered`    the steps saved at least once (one `onboarding.step_completed` per step, then only when the value changes).
 * No values are kept there: each step writes its column at once.
 */
final class OnboardingChoices
{
    public const META_KEY = 'twa_onboarding';

    /** The steps a client saves (`PUT /onboarding/steps/{step}`), in the order of the screens O2-O7. */
    public const STEPS = ['name', 'age', 'goals', 'level', 'daily_goal', 'reminder'];

    /** The summary O8: a screen, not a step - nothing is saved there, `POST /onboarding/complete` ends the onboarding. */
    public const PLAN = 'plan';

    /** Every screen `step` may point at. */
    public const SCREENS = [...self::STEPS, self::PLAN];

    public const AGE_RANGES = ['lt14', '14_17', '18_24', '25_34', '35_44', '45p'];

    /** Why the learner studies English (O4): not the exam-tag `learners.goal` of the bot. */
    public const GOALS = ['career', 'study', 'travel', 'relocation', 'communication', 'media'];

    /** The goal of the day the summary of the kit recommends (docs/40 §3, В3). */
    public const RECOMMENDED_DAILY_GOAL = 20;

    /** The times of the reminder screen of the kit (O7); "Your own" is any quarter of an hour (LearnerSettingsService). */
    public const REMINDER_TIMES = ['08:00', '12:30', '18:00', '20:00', '21:30'];

    public const WRITE_ACCESS = ['granted', 'denied', 'not_asked'];

    public const CHANNEL = 'mini_app';

    /** The screen after `$step` (the summary after the last one). */
    public static function next(string $step): string
    {
        $index = array_search($step, self::STEPS, true);

        return $index === false ? self::PLAN : (self::STEPS[$index + 1] ?? self::PLAN);
    }

    /**
     * Where the Mini App resumes the onboarding (`/auth`, `/me`, `GET /onboarding`) from the row already loaded - no statement: null when
     * it is done, and null when no step was saved yet (the welcome screen O1).
     */
    public static function resumeStep(Learner $learner): ?string
    {
        if ($learner->onboarding_state === OnboardingService::DONE) {
            return null;
        }

        $step = self::progress($learner)['step'] ?? null;

        return is_string($step) && in_array($step, self::SCREENS, true) ? $step : null;
    }

    /** @return array<string, mixed> `meta.twa_onboarding` of the row (empty when there is none) */
    public static function progress(Learner $learner): array
    {
        $progress = ($learner->meta ?? [])[self::META_KEY] ?? null;

        return is_array($progress) ? $progress : [];
    }
}
