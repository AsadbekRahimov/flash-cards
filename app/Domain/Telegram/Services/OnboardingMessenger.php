<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learners\DTO\OnboardingStep;
use App\Domain\Learners\DTO\StartPayload;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Organizations\Services\LearnerOrganizationsService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Models\Course;
use App\Models\Learner;
use Carbon\CarbonImmutable;

/**
 * Renders onboarding and `/settings` screens: message text from `lang/{ru,uz}/onboarding.php` in the
 * learner's language plus the inline keyboard. All the rules live in {@see OnboardingService};
 * this class only talks to Telegram.
 *
 * `callback_data` is `<prefix>:<step>:<value>` (≤ 64 bytes; values come from fixed whitelists
 * and IANA ids), the prefix carries the format version so a future layout can ignore old buttons:
 *   onb1 — answer to the onboarding step;
 *   set1 — `/settings`: `set1:<step>` opens the step, `set1:<step>:<value>` saves the answer.
 */
final class OnboardingMessenger
{
    public const ONBOARDING_PREFIX = 'onb1';

    public const SETTINGS_PREFIX = 'set1';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly OnboardingService $onboarding,
        private readonly CourseCatalogService $catalog,
        private readonly LearnerClock $clock,
        private readonly LearnerOrganizationsService $organizations,
    ) {}

    /** `/start` and any message of a learner who has not finished the onboarding: begin it or repeat the current step. */
    public function resume(Learner $learner, int|string $chatId, StartPayload $payload): void
    {
        $this->sendStep($learner, $chatId, $this->onboarding->start($learner, $payload));
    }

    public function sendStep(Learner $learner, int|string $chatId, OnboardingStep $step): void
    {
        $locale = $this->locales->forLearner($learner);

        if ($step->isDone()) {
            $course = $learner->active_course_id === null ? null : Course::query()->find($learner->active_course_id);

            $this->telegram->sendWebAppButton(
                chatId: $chatId,
                text: $course === null
                    ? __('onboarding.done.text_plain', [], $locale)
                    : __('onboarding.done.text', ['course' => $this->courseTitle($course, $locale)], $locale),
                buttonText: __('onboarding.done.button', [], $locale),
                url: rtrim((string) config('twa.base_url'), '/').'/twa/?from=onboarding',
            );

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            __($step->messageKey, [], $locale),
            replyMarkup: $this->keyboard($step, $locale, self::ONBOARDING_PREFIX),
        );
    }

    /**
     * `/start c_<slug>` of a learner who has finished the onboarding: a button that opens that
     * course in the app (`/twa/courses/<slug>`). Nothing when the link has no course or the
     * course is not available (draft, private, archived, deleted).
     */
    public function sendCourseLink(Learner $learner, int|string $chatId, StartPayload $payload): void
    {
        $slug = $payload->courseSlug();
        $course = $slug === null ? null : $this->catalog->findAvailable($slug);
        if ($course === null) {
            return;
        }

        $locale = $this->locales->forLearner($learner);

        $this->telegram->sendWebAppButton(
            chatId: $chatId,
            text: __('onboarding.course_link.text', ['course' => $this->courseTitle($course, $locale)], $locale),
            buttonText: __('onboarding.course_link.button', [], $locale),
            url: rtrim((string) config('twa.base_url'), '/').'/twa/courses/'.rawurlencode($course->slug),
        );
    }

    /** `/settings`: which value to change. */
    public function sendSettingsMenu(Learner $learner, int|string $chatId): void
    {
        $locale = $this->locales->forLearner($learner);

        $buttons = array_map(
            fn (string $step): array => [
                'text' => __('onboarding.settings.buttons.'.$step, [], $locale),
                'callback_data' => self::SETTINGS_PREFIX.':'.$step,
            ],
            OnboardingService::STEPS,
        );

        $rows = array_chunk($buttons, 2);
        // "My centers" (docs/13 §6.2) only for a learner who is in one; its buttons are OrganizationMessenger's.
        if ($this->organizations->hasMemberships($learner)) {
            $rows[] = [[
                'text' => __('organizations.settings_button', [], $locale),
                'callback_data' => OrganizationMessenger::PREFIX.':list',
            ]];
        }

        $this->telegram->sendMessage($chatId, __('onboarding.settings.menu', [], $locale), replyMarkup: [
            'inline_keyboard' => $rows,
        ]);
    }

    /** `/settings`: the options of one step. */
    public function sendSettingStep(Learner $learner, int|string $chatId, string $step): void
    {
        $locale = $this->locales->forLearner($learner);
        // The caller has checked that $step is one of OnboardingService::STEPS.
        $stepView = $this->onboarding->step($step);

        $this->telegram->sendMessage(
            $chatId,
            __($stepView->messageKey, [], $locale),
            replyMarkup: $this->keyboard($stepView, $locale, self::SETTINGS_PREFIX),
        );
    }

    public function sendSettingSaved(Learner $learner, int|string $chatId): void
    {
        $this->telegram->sendMessage($chatId, __('onboarding.settings.saved', [], $this->locales->forLearner($learner)));
    }

    /** `/settings` refused a time-zone change (once per 24 h): says when it is possible again, in the learner's current local time. */
    public function sendTimezoneChangeTooSoon(Learner $learner, int|string $chatId, CarbonImmutable $availableAt): void
    {
        $local = $availableAt->setTimezone($this->clock->timezone($learner));

        $this->telegram->sendMessage(
            $chatId,
            __('onboarding.settings.timezone_too_soon', ['when' => $local->format('d.m H:i')], $this->locales->forLearner($learner)),
        );
    }

    /** Removes the buttons of an answered message; a message Telegram no longer lets us edit is not worth failing for. */
    public function clearKeyboard(int|string $chatId, int $messageId): void
    {
        try {
            $this->telegram->editMessageReplyMarkup($chatId, $messageId, null);
        } catch (TelegramApiException) {
            // Deleted, too old or already without buttons.
        }
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function keyboard(OnboardingStep $step, string $locale, string $prefix): array
    {
        $buttons = [];
        foreach ($step->options as $option) {
            $buttons[] = [
                'text' => $option->label ?? __((string) $option->labelKey, $option->labelParams, $locale),
                'callback_data' => $prefix.':'.$step->state.':'.$option->value,
            ];
        }

        return ['inline_keyboard' => array_chunk($buttons, max(1, $step->columns))];
    }

    private function courseTitle(Course $course, string $locale): string
    {
        foreach ([$locale, $this->locales->fallback()] as $key) {
            $title = $course->title[$key] ?? null;
            if (is_string($title) && trim($title) !== '') {
                return $title;
            }
        }

        return $course->slug;
    }
}
