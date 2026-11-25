<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Learners\Services\LearnerRegionService;
use App\Domain\Learners\Services\LearnerSettingsService;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Learners\Services\ProEmojiService;
use App\Domain\Learners\Support\Regions;
use App\Domain\Learning\DTO\LearnerExerciseSettings;
use App\Domain\Learning\Support\LadderPolicy;
use App\Domain\Localization\Services\LocaleResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/twa/me/settings — a partial update: only the fields present are validated and changed.
 * Access is the `TwaAuth` middleware; the same rules are enforced again by LearnerSettingsService.
 * `timezone` is not a rule any more (owner's decision of 16.11.2026): `validated()` only returns fields with a rule, so a
 * cached older client that still sends it gets its other fields saved, the zone is ignored and there is no 422.
 */
final class UpdateSettingsRequest extends FormRequest
{
    /**
     * The settings of the request: everything validated but `region_via`, which is where the change comes from, not a setting.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $validated = $this->validated();
        unset($validated['region_via']);

        return $validated;
    }

    public function regionVia(): string
    {
        $via = $this->validated('region_via');

        return is_string($via) ? $via : LearnerRegionService::VIA_SETTINGS;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $settings = $this->container->make(LearnerSettingsService::class);

        return [
            'interface_locale' => ['sometimes', 'string', Rule::in($this->container->make(LocaleResolver::class)->supported())],
            // `null` turns the reminders off; otherwise HH:MM on a quarter of an hour.
            'reminder_time' => ['sometimes', 'nullable', 'string', 'regex:/^([01]\d|2[0-3]):(00|15|30|45)$/'],
            'daily_goal' => ['sometimes', 'integer:strict', Rule::in($settings->dailyGoals())],
            'goal' => ['sometimes', 'string', Rule::in(OnboardingService::availableGoals())],
            'level' => ['sometimes', 'string', Rule::in(OnboardingService::LEVELS)],
            // Leagues (docs/13 §7.3): a JSON boolean only — no "1" / "on".
            'leagues_opt_out' => ['sometimes', 'boolean:strict'],
            // A partial object: only the exercise types given change (docs/13 §8.2).
            'exercises' => ['sometimes', Rule::array(LearnerExerciseSettings::KEYS)],
            'exercises.*' => ['boolean:strict'],
            // The FSRS retention mode (docs/06 §12.8).
            'retention_mode' => ['sometimes', 'string', Rule::in(LadderPolicy::RETENTION_MODES)],
            // The region (docs/35 §2.3): a code of config/regions.php, `null` = "rather not say"; `region_via` says which screen
            // chose it (`settings` / `prompt` - the card of the Rating tab), for the product event only.
            'region_code' => ['sometimes', 'nullable', 'string', Rule::in(Regions::codes())],
            'region_via' => ['sometimes', 'string', Rule::in(LearnerRegionService::CLIENT_VIAS)],
            // The emoji of Pro (docs/31 §5а): a CODE of config/pro_emoji.php, never the emoji or free text; `null` takes it back.
            'pro_emoji' => ['sometimes', 'nullable', 'string', Rule::in(array_keys($this->container->make(ProEmojiService::class)->set()))],
        ];
    }
}
