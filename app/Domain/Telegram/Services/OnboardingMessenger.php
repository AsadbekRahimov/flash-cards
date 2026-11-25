<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Engagement\Services\FriendshipService;
use App\Domain\Learners\DTO\OnboardingStep;
use App\Domain\Learners\DTO\StartPayload;
use App\Domain\Learners\Enums\StartPayloadKind;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Organizations\Exceptions\OrganizationMembershipException;
use App\Domain\Organizations\Services\LearnerOrganizationsService;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Learners\Support\Regions;
use App\Domain\Telegram\Support\BotButton;
use App\Domain\Telegram\Support\TwaScreen;
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
 * The region step (docs/35 §2.3) has two screens in ONE message: `…:region:uz` and `…:region:back` edit it to the other screen
 * ({@see editStep()}); every button is made by {@see BotButtons} (≤ 64 bytes).
 */
final class OnboardingMessenger
{
    public const ONBOARDING_PREFIX = 'onb1';

    public const SETTINGS_PREFIX = 'set1';

    /** `set1:tzundo` — the button that takes back a just-made change of the time zone. */
    public const TIMEZONE_UNDO_STEP = 'tzundo';

    /** `set1:rgundo` — the button that takes back a just-made change of the region. */
    public const REGION_UNDO_STEP = 'rgundo';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly OnboardingService $onboarding,
        private readonly CourseCatalogService $catalog,
        private readonly LearnerOrganizationsService $organizations,
        private readonly OrganizationMembershipService $memberships,
        private readonly FriendshipService $friendships,
        private readonly BotButtons $buttons,
    ) {}

    /**
     * `/start` and any message of a learner who has not finished the onboarding: begin it or repeat the current step.
     * `$greet` (the `/start` command): the first step is preceded by a short welcome and, for an invitation link, by who invited.
     */
    public function resume(Learner $learner, int|string $chatId, StartPayload $payload, bool $greet = false): void
    {
        $step = $this->onboarding->start($learner, $payload);

        $this->sendStep($learner, $chatId, $step, $greet && $step->state === OnboardingService::steps()[0] ? $payload : null);
    }

    /** @param StartPayload|null $welcome set for the first message of a new person: the welcome (and the inviter) is put before the step */
    public function sendStep(Learner $learner, int|string $chatId, OnboardingStep $step, ?StartPayload $welcome = null): void
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
                url: $this->buttons->twaUrl(TwaScreen::Today, query: ['from' => 'onboarding']),
            );

            return;
        }

        $text = $this->stepText($step, $locale);

        if ($welcome !== null) {
            $invited = $this->invitedBy($learner, $welcome, $locale);
            $text = ($invited === null ? '' : $invited."\n\n").__('onboarding.welcome', [], 'ru')."\n\n".__('onboarding.welcome', [], 'uz')."\n\n".$text;
        }

        $this->telegram->sendMessage($chatId, $text, replyMarkup: $this->keyboard($step, $locale, self::ONBOARDING_PREFIX, $chatId));
    }

    /**
     * The other screen of a step in the same message (the two screens of the region step): `$prefix` is the onboarding's (with the
     * counter of steps) or the settings'. A message Telegram no longer lets us edit gets the screen as a new message.
     */
    public function editStep(Learner $learner, int|string $chatId, int $messageId, OnboardingStep $step, string $prefix): void
    {
        $locale = $this->locales->forLearner($learner);
        $text = $prefix === self::ONBOARDING_PREFIX ? $this->stepText($step, $locale) : (string) __($step->messageKey, [], $locale);
        $keyboard = $this->keyboard($step, $locale, $prefix, $chatId);

        try {
            $this->telegram->editMessageText($chatId, $messageId, $text, replyMarkup: $keyboard);
        } catch (TelegramApiException) {
            $this->telegram->sendMessage($chatId, $text, replyMarkup: $keyboard);
        }
    }

    /** "Step n of N" and the question of the step. */
    private function stepText(OnboardingStep $step, string $locale): string
    {
        $steps = OnboardingService::steps();

        return __('onboarding.step_counter', [
            'n' => (int) array_search($step->state, $steps, true) + 1,
            'total' => count($steps),
        ], $locale)."\n".__($step->messageKey, [], $locale);
    }

    /**
     * "Invited by center X / by Y" for an `org_` / `f_` / `ref_` link: the name is looked up here, a code that names nobody
     * (unknown, expired, the person's own link) gives no line. The link itself is handled elsewhere (OrganizationMessenger, FriendsMessenger).
     */
    private function invitedBy(Learner $learner, StartPayload $payload, string $locale): ?string
    {
        if ($payload->value === null) {
            return null;
        }

        if ($payload->kind === StartPayloadKind::Org) {
            try {
                $name = $this->memberships->previewInvite($payload->value)->organization->name;
            } catch (OrganizationMembershipException) {
                return null;
            }

            $line = __('onboarding.invited_by_organization', ['organization' => $name], $locale);

            return is_string($line) ? $line : null;
        }

        if (! in_array($payload->kind, [StartPayloadKind::Friend, StartPayloadKind::Ref], true)) {
            return null;
        }

        $inviter = $this->friendships->ownerOfCode($payload->value);
        $name = $inviter === null ? '' : trim((string) $inviter->first_name);

        if ($inviter === null || $inviter->id === $learner->id || $name === '') {
            return null;
        }

        $line = __('onboarding.invited_by_learner', ['name' => $name], $locale);

        return is_string($line) ? $line : null;
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
            url: $this->buttons->twaUrl(TwaScreen::Course, ['slug' => $course->slug]),
        );
    }

    /** `/settings`: which value to change. */
    public function sendSettingsMenu(Learner $learner, int|string $chatId): void
    {
        $locale = $this->locales->forLearner($learner);

        $buttons = array_map(
            fn (string $step): BotButton => $this->buttons->callback(__('onboarding.settings.buttons.'.$step, [], $locale), self::SETTINGS_PREFIX.':'.$step),
            OnboardingService::steps(),
        );

        $rows = array_chunk($buttons, 2);
        // "My centers" (docs/13 §6.2) only for a learner who is in one; its buttons are OrganizationMessenger's.
        if ($this->organizations->hasMemberships($learner)) {
            $rows[] = [$this->buttons->callback(__('organizations.settings_button', [], $locale), OrganizationMessenger::PREFIX.':list')];
        }

        // "Delete account" last, alone in its row: its own confirmation follows (AccountDeletionMessenger).
        $rows[] = [$this->buttons->callback(__('bot.delete_account.settings_button', [], $locale), AccountDeletionMessenger::ASK)];

        $this->telegram->sendMessage($chatId, __('onboarding.settings.menu', [], $locale), replyMarkup: $this->buttons->keyboard($chatId, $rows));
    }

    /** `/settings`: the options of one step. */
    public function sendSettingStep(Learner $learner, int|string $chatId, string $step): void
    {
        $locale = $this->locales->forLearner($learner);
        // The caller has checked that $step is one of OnboardingService::steps().
        $stepView = $this->onboarding->step($step);

        $this->telegram->sendMessage(
            $chatId,
            __($stepView->messageKey, [], $locale),
            replyMarkup: $this->keyboard($stepView, $locale, self::SETTINGS_PREFIX, $chatId),
        );
    }

    /**
     * `/language`: the language step of `/settings` at once. The title is in both languages — the person who looks for it may
     * not read the current one; the buttons are the settings' own (`set1:locale:<code>`), so saving is the one path of `/settings`.
     */
    public function sendLanguagePicker(Learner $learner, int|string $chatId): void
    {
        $locale = $this->locales->forLearner($learner);
        $stepView = $this->onboarding->step('locale');

        $this->telegram->sendMessage($chatId, __('onboarding.settings.language_title', [], 'uz').' · '.__('onboarding.settings.language_title', [], 'ru'), replyMarkup: $this->keyboard($stepView, $locale, self::SETTINGS_PREFIX, $chatId));
    }

    public function sendSettingSaved(Learner $learner, int|string $chatId): void
    {
        $this->telegram->sendMessage($chatId, __('onboarding.settings.saved', [], $this->locales->forLearner($learner)));
    }

    /** `/settings` → region saved: the new region and the one "Undo" button (10 minutes, {@see REGION_UNDO_STEP}). */
    public function sendRegionSaved(Learner $learner, int|string $chatId): void
    {
        $locale = $this->locales->forLearner($learner);
        $region = $learner->region_code === null ? __('onboarding.settings.region_none', [], $locale) : Regions::name($learner->region_code, $locale);

        $this->telegram->sendMessage(
            $chatId,
            __('onboarding.settings.region_saved', ['region' => $region], $locale),
            replyMarkup: $this->buttons->single($chatId, $this->buttons->callback(__('onboarding.settings.region_undo', [], $locale), self::SETTINGS_PREFIX.':'.self::REGION_UNDO_STEP)),
        );
    }

    /** "You can change the region from 24.12.2026" (the date in the learner's zone). */
    public function sendRegionChangeTooSoon(Learner $learner, int|string $chatId, CarbonImmutable $availableAt): void
    {
        $this->telegram->sendMessage($chatId, __('onboarding.settings.region_too_soon', [
            'date' => $availableAt->setTimezone($learner->timezone)->format('d.m.Y'),
        ], $this->locales->forLearner($learner)));
    }

    public function sendRegionReverted(Learner $learner, int|string $chatId): void
    {
        $this->telegram->sendMessage($chatId, __('onboarding.settings.region_reverted', [], $this->locales->forLearner($learner)));
    }

    public function sendRegionRevertUnavailable(Learner $learner, int|string $chatId): void
    {
        $this->telegram->sendMessage($chatId, __('onboarding.settings.region_revert_unavailable', [], $this->locales->forLearner($learner)));
    }

    public function sendTimezoneReverted(Learner $learner, int|string $chatId): void
    {
        $this->telegram->sendMessage($chatId, __('onboarding.settings.timezone_reverted', [], $this->locales->forLearner($learner)));
    }

    public function sendTimezoneRevertUnavailable(Learner $learner, int|string $chatId): void
    {
        $this->telegram->sendMessage($chatId, __('onboarding.settings.timezone_revert_unavailable', [], $this->locales->forLearner($learner)));
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
     * The buttons of a step, `$step->columns` in a row; the "← Back" of the regions alone in the last row.
     *
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}
     */
    private function keyboard(OnboardingStep $step, string $locale, string $prefix, int|string $chatId): array
    {
        $buttons = [];
        $last = [];
        foreach ($step->options as $option) {
            $button = $this->buttons->callback(
                (string) ($option->label ?? __((string) $option->labelKey, $option->labelParams, $locale)),
                $prefix.':'.$step->state.':'.$option->value,
            );
            if ($step->state === 'region' && $option->value === OnboardingService::REGION_BACK) {
                $last[] = [$button];

                continue;
            }
            $buttons[] = $button;
        }

        return $this->buttons->keyboard($chatId, [...array_chunk($buttons, max(1, $step->columns)), ...$last]);
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
