<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learners\DTO\OnboardingOption;
use App\Domain\Learners\DTO\OnboardingStep;
use App\Domain\Learners\DTO\StartPayload;
use App\Domain\Learners\Enums\StartPayloadKind;
use App\Domain\Learners\Exceptions\OnboardingException;
use App\Domain\Learners\Exceptions\RegionChangeTooSoonException;
use App\Domain\Learners\Support\Regions;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Organizations\Services\OrganizationInviteService;
use App\Jobs\OfferPendingInvitesJob;
use App\Models\Course;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The `/start` onboarding in the bot (docs/12 §7.1): interface language → region (docs/35 §2.3) → goal → level (self-assessment)
 * → reminder time. The goal step exists only while more than one goal is enabled (`learners.goals`,
 * {@see self::availableGoals()}): with one, it is skipped and that goal is written. There is no time zone step
 * (owner's decision of 16.11.2026): a new learner gets `learners.default_timezone`. `learners.onboarding_state`
 * is the step the learner has to answer now (`new` until `/start`, then `locale` … `reminder`, finally `done`).
 * A learner stuck on a step that no longer exists (`timezone`, or `goal` while it is off) is moved to the next
 * live one by {@see self::settle()}.
 *
 * The region step has two screens (docs/35 §2.3): "Uzbekistan / Another country / Rather not say", and after "Uzbekistan" the 14
 * regions with "← Back". "Uzbekistan" and "Back" are not answers: {@see self::regionScreen()} only gives the other screen (the state
 * stays `region`), so an old button of the first screen after the answer is a stale one. A region, "Another country" and "Rather not
 * say" (NULL) answer the step through {@see LearnerRegionService::applyLocked()}. Learners already past the language step when the
 * step appeared are not taken back to it.
 *
 * `apply()` accepts only the answer to the CURRENT step, under a row lock: a tap on an old
 * message, a double tap or two parallel webhook deliveries are rejected with
 * {@see OnboardingException} and change nothing — that is what makes the buttons idempotent.
 * Every value is checked against a whitelist; `callback_data` is not signed and is treated as
 * untrusted input.
 *
 * Setting or changing the reminder time recomputes `next_reminder_at` — the next
 * UTC instant at which the learner's wall clock shows `reminder_time` ({@see LearnerClock}), `NULL`
 * when reminders are off; the reminder scheduler keeps it up to date after each send
 * (docs/12 §7.5, task 1.5b).
 */
final class OnboardingService
{
    public const DONE = 'done';

    /** In the order they are asked; the `goal` step is skipped while only one goal is enabled, see {@see self::steps()}. */
    public const STEPS = ['locale', 'region', 'goal', 'level', 'reminder'];

    /** The step that was removed from the onboarding: a learner may still be stored on it. */
    public const LEGACY_TIMEZONE_STEP = 'timezone';

    /** Every goal the system knows (captions in lang); which of them are offered is `learners.goals`. */
    public const GOALS = [self::GENERAL_GOAL, 'ielts', 'sat', 'school'];

    public const LEVELS = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'];

    /** "I don't know" is stored as A1 (docs/12 §7.1). */
    public const LEVEL_UNKNOWN = 'unknown';

    public const REMINDER_OFF = 'off';

    /** The region step, first screen: "Uzbekistan" opens the list of its regions (not an answer). */
    public const REGION_UZBEKISTAN = 'uz';

    /** The region step, second screen: "← Back" to the first one (not an answer). */
    public const REGION_BACK = 'back';

    /** The region step: "Rather not say" - no region (NULL). */
    public const REGION_NONE = 'none';

    /** Callback value => local time. */
    public const REMINDER_TIMES = ['0800' => '08:00', '1300' => '13:00', '1900' => '19:00', '2100' => '21:00'];

    /** The goal whose courses form the base catalog: the second step of the course recommendation (and the levels of the Mini App). */
    public const GENERAL_GOAL = 'general';

    /** `learners.meta` key: the course slug of a `c_<slug>` link, applied when the onboarding completes. */
    private const PENDING_COURSE = 'pending_course';

    /** `learners.meta` key: the invite code of an `org_<code>` link, offered once the onboarding is done (docs/13 §6.2). */
    private const PENDING_ORG_INVITE = 'pending_org_invite';

    /** `learners.meta` key: the owner's code of an `f_<code>` link, offered as "Add to friends" once the onboarding is done (docs/13 §7.5). */
    private const PENDING_FRIEND_INVITE = 'pending_friend_invite';

    public function __construct(
        private readonly LocaleResolver $locales,
        private readonly CourseCatalogService $catalog,
        private readonly ProductEventRecorder $events,
        private readonly LearnerSettingsService $settings,
        private readonly LearnerRegionService $regions,
    ) {}

    /**
     * The goals a learner can choose now: `config('learners.goals')` limited to the known ones, `general` when
     * nothing valid is left. Stored goals of existing learners are never touched, whatever this returns.
     *
     * @return list<string>
     */
    public static function availableGoals(): array
    {
        $configured = config('learners.goals', [self::GENERAL_GOAL]);
        $goals = array_values(array_filter(
            self::GOALS,
            fn (string $goal): bool => in_array($goal, is_array($configured) ? array_map('strval', $configured) : [], true),
        ));

        return $goals === [] ? [self::GENERAL_GOAL] : $goals;
    }

    /** @return list<string> the steps asked now, in order */
    public static function steps(): array
    {
        $goalAsked = count(self::availableGoals()) > 1;

        return array_values(array_filter(self::STEPS, fn (string $step): bool => $step !== 'goal' || $goalAsked));
    }

    /** A button of a step that is not asked any more (the old time zone picker, or the goal while it is off). */
    public static function isRetiredStep(string $step): bool
    {
        return $step === self::LEGACY_TIMEZONE_STEP || ($step === 'goal' && ! in_array('goal', self::steps(), true));
    }

    /**
     * Moves a learner stuck on a step that is not asked any more to the next live one (the goal is written when it is
     * skipped), so that the next action goes on without an error. Returns whether anything moved.
     */
    public function settle(Learner $learner): bool
    {
        $moved = false;

        DB::transaction(function () use ($learner, &$moved): void {
            $fresh = $this->lock($learner);
            $moved = $this->settleLocked($fresh);
            if ($moved || $this->fillSkippedGoal($fresh)) {
                $fresh->save();
            }
        });

        $learner->refresh();

        return $moved;
    }

    /** In memory, under the row lock: a `timezone` / retired `goal` state becomes the next live step. */
    private function settleLocked(Learner $fresh): bool
    {
        $state = $fresh->onboarding_state;
        if ($state === self::DONE || ! in_array($state, [self::LEGACY_TIMEZONE_STEP, 'goal'], true) || in_array($state, self::steps(), true)) {
            return false;
        }

        $this->fillSkippedGoal($fresh);
        $fresh->onboarding_state = $state === 'goal' ? 'level' : 'reminder';

        return true;
    }

    /** With one goal enabled the goal step is never asked: the learner gets it (only while they have none). */
    private function fillSkippedGoal(Learner $fresh): bool
    {
        $goals = self::availableGoals();
        if (count($goals) > 1 || $fresh->goal !== null) {
            return false;
        }

        $fresh->goal = $goals[0];

        return true;
    }

    /**
     * `/start` of a learner who has not finished the onboarding: `new` moves to the first step
     * (`onboarding.started`); a learner in the middle of it just gets their current step again.
     * A `c_<slug>` payload is remembered and picks the active course when the onboarding ends;
     * an `org_<code>` payload is remembered too and offered as "Join center X?" after the last step;
     * so is an `f_<code>` payload ("Add X to friends?").
     */
    public function start(Learner $learner, StartPayload $payload): OnboardingStep
    {
        $began = false;

        DB::transaction(function () use ($learner, $payload, &$began): void {
            $fresh = $this->lock($learner);
            if ($fresh->onboarding_state === self::DONE) {
                return;
            }

            $began = $fresh->onboarding_state === 'new';
            $this->settleLocked($fresh);
            if (! in_array($fresh->onboarding_state, self::steps(), true)) {
                $fresh->onboarding_state = self::steps()[0];
            }
            $this->fillSkippedGoal($fresh);

            if ($payload->courseSlug() !== null) {
                $fresh->meta = [...$fresh->meta, self::PENDING_COURSE => $payload->courseSlug()];
            }

            if ($payload->kind === StartPayloadKind::Org && $payload->value !== null) {
                $fresh->meta = [...$fresh->meta, self::PENDING_ORG_INVITE => OrganizationInviteService::normalizeCode($payload->value)];
            }

            if ($payload->friendCode() !== null) {
                // Only an address, no consent yet: nothing is written to `friendships` before the learner agrees.
                $fresh->meta = [...$fresh->meta, self::PENDING_FRIEND_INVITE => $payload->friendCode()];
            }

            $fresh->save();
        });

        $learner->refresh();

        if ($began) {
            // The kind of the attribution that was ACCEPTED (first touch wins): a rejected payload
            // (invalid, self-referral, a learner who already has a source) does not count.
            $this->events->record(ProductEventName::OnboardingStarted, [
                'source_kind' => $learner->acquisition_source === null
                    ? 'direct'
                    : explode(':', $learner->acquisition_source, 2)[0],
            ], learner: $learner);
        }

        return $this->current($learner);
    }

    /**
     * Takes the invite code of an `org_<code>` link waiting for the end of the onboarding: reads and forgets it under
     * a lock, so a double callback offers it once and a failed send does not keep it stuck.
     */
    public function pullPendingOrgInvite(Learner $learner): ?string
    {
        return $this->pull($learner, self::PENDING_ORG_INVITE);
    }

    /** Takes the owner's code of an `f_<code>` link waiting for the end of the onboarding (same contract). */
    public function pullPendingFriendInvite(Learner $learner): ?string
    {
        return $this->pull($learner, self::PENDING_FRIEND_INVITE);
    }

    private function pull(Learner $learner, string $key): ?string
    {
        $code = DB::transaction(function () use ($learner, $key): mixed {
            $fresh = $this->lock($learner);
            $meta = $fresh->meta ?? [];

            if (! array_key_exists($key, $meta)) {
                return null;
            }

            $code = $meta[$key];
            unset($meta[$key]);
            $fresh->meta = $meta;
            $fresh->save();

            return $code;
        });

        $learner->refresh();

        return is_string($code) && $code !== '' ? $code : null;
    }

    /** The step the learner has to answer now (`done` when finished; `new` counts as the first step). */
    public function current(Learner $learner): OnboardingStep
    {
        return $this->step($learner->onboarding_state);
    }

    /**
     * Saves the answer to $step and returns the next step (`done` after the last one, with the
     * active course chosen).
     *
     * @throws OnboardingException when $step is not the learner's current step or $value is not allowed
     */
    public function apply(Learner $learner, string $step, string $value): OnboardingStep
    {
        $changes = $this->changes($step, $value);
        $completed = false;
        $via = null;
        $course = null;
        $remindersDisabled = false;
        $regionChange = null;

        DB::transaction(function () use ($learner, $step, $changes, &$completed, &$course, &$via, &$remindersDisabled, &$regionChange): void {
            $fresh = $this->lock($learner);
            $this->settleLocked($fresh);
            if ($fresh->onboarding_state !== $step) {
                throw OnboardingException::staleStep($step);
            }

            // "Off" over the default time of a new learner: reminders turned off (`reminders.disabled`, audit 2026-11-18).
            $remindersDisabled = array_key_exists('reminder_time', $changes) && $changes['reminder_time'] === null
                && $fresh->reminder_time !== null && $fresh->reminder_time !== '';

            if (array_key_exists('region_code', $changes)) {
                $regionChange = $this->applyRegion($fresh, $changes['region_code']);
                unset($changes['region_code']);
            }

            $fresh->fill($changes);
            if ($step === 'locale') {
                $fresh->meta = [...$fresh->meta, LearnerSettingsService::LOCALE_CHOSEN => true];
            }
            if ($step === 'reminder') {
                $this->settings->scheduleReminder($fresh);
            }
            $this->fillSkippedGoal($fresh);

            $steps = self::steps();
            $next = $steps[array_search($step, $steps, true) + 1] ?? self::DONE;
            $fresh->onboarding_state = $next;

            if ($next === self::DONE) {
                $completed = true;
                [$course, $via] = $this->finishLocked($fresh);
            }

            $fresh->save();
        });

        $learner->refresh();

        $this->events->record(ProductEventName::OnboardingStepCompleted, ['step' => $step], learner: $learner);
        if ($regionChange !== null) {
            $this->regions->recordChange($learner, $regionChange, LearnerRegionService::VIA_ONBOARDING);
        }
        if ($remindersDisabled) {
            $this->events->record(ProductEventName::RemindersDisabled, ['via' => 'onboarding'], learner: $learner);
        }

        if ($completed) {
            $this->recordCompletion($learner, $course, $via);
        }

        return $this->current($learner);
    }

    /**
     * Ends the onboarding from outside the bot (the Mini App, docs/40 §2.1): the one place besides {@see apply()} that writes `done`, with
     * the same steps under the same row lock - the goal of a skipped goal step, the course ({@see completeCourse()}: a `c_<slug>` link,
     * the learner's own course, the recommendation for the goal and level, the general course, the default one), `onboarding_completed_at`,
     * `next_reminder_at`; then `onboarding.completed {channel, ...}` and `course.selected`. A learner already `done` (by the bot, by a
     * parallel request) is left alone: nothing is written, no event. A learner in the middle of the bot's steps jumps to `done`; the
     * bot's old buttons are stale from then on ({@see OnboardingException::staleStep()}).
     *
     * The passed model is brought up to date from the locked row (no statement of its own).
     *
     * @return array{completed: bool, course: Course|null} whether it completed now, and the course it chose now (null - none, or kept)
     */
    public function completeExternally(Learner $learner, string $channel): array
    {
        $completed = false;
        $course = null;
        $via = null;

        DB::transaction(function () use ($learner, &$completed, &$course, &$via): void {
            $fresh = $this->lock($learner);
            if ($fresh->onboarding_state === self::DONE) {
                $learner->setRawAttributes($fresh->getAttributes(), true);

                return;
            }

            $this->fillSkippedGoal($fresh);
            $fresh->onboarding_state = self::DONE;
            [$course, $via] = $this->finishLocked($fresh);
            $fresh->save();

            $learner->setRawAttributes($fresh->getAttributes(), true);
            $completed = true;
        });

        if ($completed) {
            $this->recordCompletion($learner, $course, $via, $channel);

            // The invitation remembered by `/start` is offered by the bot after its last step; here nobody would (docs/40 §5 п. 1).
            if (self::hasPendingInvite($learner)) {
                OfferPendingInvitesJob::dispatch($learner->id);
            }
        }

        return ['completed' => $completed, 'course' => $course];
    }

    /** Whether an `org_<code>` / `f_<code>` link waits for the end of the onboarding (the loaded row; no statement). */
    public static function hasPendingInvite(Learner $learner): bool
    {
        $meta = $learner->meta ?? [];

        foreach ([self::PENDING_ORG_INVITE, self::PENDING_FRIEND_INVITE] as $key) {
            if (is_string($meta[$key] ?? null) && $meta[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The end of the onboarding on the locked row (not saved): the active course, the moment, the next reminder.
     *
     * @return array{0: Course|null, 1: string|null}
     */
    private function finishLocked(Learner $fresh): array
    {
        $chosen = $this->completeCourse($fresh);
        $fresh->onboarding_completed_at = now();
        $this->settings->scheduleReminder($fresh);

        return $chosen;
    }

    /** `onboarding.completed` (with `channel` when it is not the bot) and `course.selected` for a course chosen now. */
    private function recordCompletion(Learner $learner, ?Course $course, ?string $via, ?string $channel = null): void
    {
        $this->events->record(ProductEventName::OnboardingCompleted, [
            'goal' => $learner->goal,
            'level' => $learner->level,
            'locale' => $learner->interface_locale,
            ...($channel === null ? [] : ['channel' => $channel]),
        ], learner: $learner);

        if ($course !== null) {
            $this->events->record(ProductEventName::CourseSelected, [
                'course_id' => $course->id,
                'via' => $via,
            ], learner: $learner);
        }
    }

    /**
     * The other screen of the region step, for "Uzbekistan" (the 14 regions) or "← Back" (the first screen) of the onboarding: only
     * while the learner is on that step - an old button is a stale one. Nothing is written.
     *
     * @throws OnboardingException staleStep when the learner is not on the region step, invalidValue for another value
     */
    public function regionScreen(Learner $learner, string $value): OnboardingStep
    {
        if (! in_array($value, [self::REGION_UZBEKISTAN, self::REGION_BACK], true)) {
            throw OnboardingException::invalidValue('region');
        }

        $state = Learner::query()->whereKey($learner->id)->value('onboarding_state');
        if ($state !== 'region') {
            throw OnboardingException::staleStep('region');
        }

        return $this->regionStep($value, $this->locales->forLearner($learner));
    }

    /** The screen of the region step: the first one, or the 14 regions after "Uzbekistan" in the order of `$locale` (also for `/settings`). */
    public function regionStep(string $screen = self::REGION_BACK, ?string $locale = null): OnboardingStep
    {
        if ($screen !== self::REGION_UZBEKISTAN) {
            return new OnboardingStep('region', 'onboarding.steps.region', $this->options('region'), 1);
        }

        return new OnboardingStep('region', 'onboarding.steps.region_list', [
            ...array_map(
                fn (string $code): OnboardingOption => new OnboardingOption($code, 'regions.'.$code),
                Regions::ordered($locale ?? $this->locales->fallback()),
            ),
            new OnboardingOption(self::REGION_BACK, 'onboarding.options.region.back'),
        ], 2);
    }

    /**
     * The answer of the region step on the locked row. The onboarding is the first choice and is accepted; a learner who has
     * already chosen a region elsewhere (the Mini App) inside the last 30 days keeps it - the step is passed all the same.
     *
     * @return array{from: string|null, to: string|null}|null
     */
    private function applyRegion(Learner $fresh, mixed $code): ?array
    {
        try {
            return $this->regions->applyLocked($fresh, is_string($code) ? $code : null, CarbonImmutable::now());
        } catch (RegionChangeTooSoonException) {
            return null;
        }
    }

    /**
     * `/settings` — change one onboarding value of a learner who has finished the onboarding.
     * Same whitelists as {@see self::apply()}, but no state machine; the change itself (and the
     * `next_reminder_at` it implies) is {@see LearnerSettingsService::update()}.
     *
     * @throws OnboardingException when the onboarding is not finished or $value is not allowed for $step
     * @throws RegionChangeTooSoonException when the region changes before 30 days have passed since the last choice
     */
    public function applySetting(Learner $learner, string $step, string $value): void
    {
        $changes = $this->changes($step, $value);

        // `done` is terminal (nothing moves a learner back), so a read before the update is as good as a lock.
        $state = Learner::query()->whereKey($learner->id)->value('onboarding_state');
        if ($state !== self::DONE) {
            throw OnboardingException::staleStep($step);
        }

        $this->settings->update($learner, $changes);
    }

    /** The message and buttons of $step (`done` and unknown states give the final / first one). */
    public function step(string $state): OnboardingStep
    {
        return match ($state) {
            self::DONE => new OnboardingStep(self::DONE, 'onboarding.done.text'),
            'goal' => in_array('goal', self::steps(), true)
                ? new OnboardingStep('goal', 'onboarding.steps.goal', $this->options('goal'), 2)
                : $this->step('level'),
            'region' => $this->regionStep(),
            'level', 'reminder' => new OnboardingStep($state, 'onboarding.steps.'.$state, $this->options($state), $state === 'level' ? 3 : 2),
            // A state stored before the time zone step was removed: the step after it.
            self::LEGACY_TIMEZONE_STEP => $this->step('reminder'),
            default => new OnboardingStep('locale', 'onboarding.steps.locale', $this->options('locale')),
        };
    }

    /** @return list<OnboardingOption> */
    public function options(string $step): array
    {
        switch ($step) {
            case 'locale':
                return array_map(
                    fn (string $locale): OnboardingOption => new OnboardingOption($locale, label: (string) config('localization.names.'.$locale, $locale)),
                    $this->locales->supported(),
                );
            case 'region':
                return [
                    new OnboardingOption(self::REGION_UZBEKISTAN, 'onboarding.options.region.uzbekistan'),
                    new OnboardingOption(Regions::OTHER, 'onboarding.options.region.other'),
                    new OnboardingOption(self::REGION_NONE, 'onboarding.options.region.none'),
                ];
            case 'goal':
                return array_map(
                    fn (string $goal): OnboardingOption => new OnboardingOption($goal, 'onboarding.options.goal.'.$goal),
                    self::availableGoals(),
                );
            case 'level':
                return array_map(
                    fn (string $level): OnboardingOption => new OnboardingOption($level, 'onboarding.options.level.'.$level),
                    [...self::LEVELS, self::LEVEL_UNKNOWN],
                );
            case 'reminder':
                return [
                    ...array_map(
                        fn (string $key): OnboardingOption => new OnboardingOption($key, 'onboarding.options.reminder.time', ['time' => self::REMINDER_TIMES[$key]]),
                        array_map('strval', array_keys(self::REMINDER_TIMES)),
                    ),
                    new OnboardingOption(self::REMINDER_OFF, 'onboarding.options.reminder.off'),
                ];
            default:
                return [];
        }
    }

    /**
     * Column changes for an answer, after checking it against the whitelist of the step.
     *
     * @return array<string, mixed>
     *
     * @throws OnboardingException
     */
    private function changes(string $step, string $value): array
    {
        return match ($step) {
            'locale' => in_array($value, $this->locales->supported(), true)
                ? ['interface_locale' => $value]
                : throw OnboardingException::invalidValue($step),
            'region' => match (true) {
                $value === self::REGION_NONE => ['region_code' => null],
                Regions::isValid($value) => ['region_code' => $value],
                default => throw OnboardingException::invalidValue($step),
            },
            'goal' => in_array($value, self::availableGoals(), true)
                ? ['goal' => $value]
                : throw OnboardingException::invalidValue($step),
            'level' => match (true) {
                $value === self::LEVEL_UNKNOWN => ['level' => 'A1'],
                in_array($value, self::LEVELS, true) => ['level' => $value],
                default => throw OnboardingException::invalidValue($step),
            },
            'reminder' => match (true) {
                $value === self::REMINDER_OFF => ['reminder_time' => null],
                array_key_exists($value, self::REMINDER_TIMES) => ['reminder_time' => self::REMINDER_TIMES[$value]],
                default => throw OnboardingException::invalidValue($step),
            },
            default => throw OnboardingException::invalidValue($step),
        };
    }

    /**
     * Sets `active_course_id` as the onboarding ends: the course of a `c_<slug>` link when it is
     * still available, else (unless the learner already picked one) the recommendation for the
     * goal and level, else the general course of the level, else the default course - only when it
     * can be opened and has words. With none of them the learner stays without an active course.
     *
     * @return array{0: Course|null, 1: string|null} the course chosen now and how (`deeplink` / `onboarding`)
     */
    private function completeCourse(Learner $learner): array
    {
        $meta = $learner->meta;
        $slug = $meta[self::PENDING_COURSE] ?? null;
        unset($meta[self::PENDING_COURSE]);
        $learner->meta = $meta;

        $linked = is_string($slug) ? $this->catalog->findAvailable($slug) : null;
        if ($linked !== null) {
            $learner->active_course_id = $linked->id;

            return [$linked, 'deeplink'];
        }

        if ($learner->active_course_id !== null) {
            return [null, null];
        }

        $course = $this->catalog->recommend($learner->goal, $learner->level);
        if ($course === null && $learner->goal !== self::GENERAL_GOAL) {
            $course = $this->catalog->recommend(self::GENERAL_GOAL, $learner->level);
        }
        // The default course is a fallback only while it can be opened (published, not private) and has words.
        if ($course === null) {
            $default = $this->catalog->findAvailable($this->catalog->defaultCourse()->slug);
            $course = $default !== null && $default->words_count > 0 ? $default : null;
        }
        if ($course === null) {
            return [null, null];
        }

        $learner->active_course_id = $course->id;

        return [$course, 'onboarding'];
    }

    private function lock(Learner $learner): Learner
    {
        return Learner::query()->whereKey($learner->id)->lockForUpdate()->firstOrFail();
    }
}
