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
use App\Domain\Learners\Exceptions\TimezoneChangeTooSoonException;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Organizations\Services\OrganizationInviteService;
use App\Models\Course;
use App\Models\Learner;
use Illuminate\Support\Facades\DB;

/**
 * The five-step `/start` onboarding in the bot (docs/12 §7.1): interface language → goal → level
 * (self-assessment) → time zone → reminder time. `learners.onboarding_state` is the step the
 * learner has to answer now (`new` until `/start`, then `locale` … `reminder`, finally `done`).
 *
 * `apply()` accepts only the answer to the CURRENT step, under a row lock: a tap on an old
 * message, a double tap or two parallel webhook deliveries are rejected with
 * {@see OnboardingException} and change nothing — that is what makes the buttons idempotent.
 * Every value is checked against a whitelist; `callback_data` is not signed and is treated as
 * untrusted input.
 *
 * The first time zone is set here directly. When the learner already changed the zone through the
 * settings (`meta.timezone_changed_at`) or a carried quota day is running, the zone step goes through
 * {@see LearnerSettingsService::update()} instead: 24 h between changes and the day carry apply, a
 * refusal ({@see TimezoneChangeTooSoonException}) leaves the step open.
 *
 * Setting or changing the time zone or the reminder time recomputes `next_reminder_at` — the next
 * UTC instant at which the learner's wall clock shows `reminder_time` ({@see LearnerClock}), `NULL`
 * when reminders are off; the reminder scheduler keeps it up to date after each send
 * (docs/12 §7.5, task 1.5b).
 */
final class OnboardingService
{
    public const DONE = 'done';

    /** In the order they are asked. */
    public const STEPS = ['locale', 'goal', 'level', 'timezone', 'reminder'];

    public const GOALS = ['general', 'ielts', 'sat', 'school'];

    public const LEVELS = ['A1', 'A2', 'B1', 'B2'];

    /** "I don't know" is stored as A1 (docs/12 §7.1). */
    public const LEVEL_UNKNOWN = 'unknown';

    public const REMINDER_OFF = 'off';

    /** Callback value => local time. */
    public const REMINDER_TIMES = ['0800' => '08:00', '1300' => '13:00', '1900' => '19:00', '2100' => '21:00'];

    /** `learners.meta` key: the course slug of a `c_<slug>` link, applied when the onboarding completes. */
    private const PENDING_COURSE = 'pending_course';

    /** `learners.meta` key: the invite code of an `org_<code>` link, offered once the onboarding is done (docs/13 §6.2). */
    private const PENDING_ORG_INVITE = 'pending_org_invite';

    public function __construct(
        private readonly LocaleResolver $locales,
        private readonly CourseCatalogService $catalog,
        private readonly ProductEventRecorder $events,
        private readonly LearnerSettingsService $settings,
        private readonly LearnerClock $clock,
    ) {}

    /**
     * `/start` of a learner who has not finished the onboarding: `new` moves to the first step
     * (`onboarding.started`); a learner in the middle of it just gets their current step again.
     * A `c_<slug>` payload is remembered and picks the active course when the onboarding ends;
     * an `org_<code>` payload is remembered too and offered as "Join center X?" after the last step.
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
            if (! in_array($fresh->onboarding_state, self::STEPS, true)) {
                $fresh->onboarding_state = self::STEPS[0];
            }

            if ($payload->courseSlug() !== null) {
                $fresh->meta = [...$fresh->meta, self::PENDING_COURSE => $payload->courseSlug()];
            }

            if ($payload->kind === StartPayloadKind::Org && $payload->value !== null) {
                $fresh->meta = [...$fresh->meta, self::PENDING_ORG_INVITE => OrganizationInviteService::normalizeCode($payload->value)];
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

    /** The invite code of an `org_<code>` link waiting for the end of the onboarding, if any. */
    public function pendingOrganizationInvite(Learner $learner): ?string
    {
        $code = ($learner->meta ?? [])[self::PENDING_ORG_INVITE] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /** Forgets the pending invite once it has been offered (or turned out to be unusable). */
    public function clearPendingOrganizationInvite(Learner $learner): void
    {
        DB::transaction(function () use ($learner): void {
            $fresh = $this->lock($learner);
            $meta = $fresh->meta;

            if (! array_key_exists(self::PENDING_ORG_INVITE, $meta)) {
                return;
            }

            unset($meta[self::PENDING_ORG_INVITE]);
            $fresh->meta = $meta;
            $fresh->save();
        });

        $learner->refresh();
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

        DB::transaction(function () use ($learner, $step, $changes, &$completed, &$course, &$via): void {
            $fresh = $this->lock($learner);
            if ($fresh->onboarding_state !== $step) {
                throw OnboardingException::staleStep($step);
            }

            if ($step === 'timezone' && $this->zoneChangeIsGuarded($fresh)) {
                // Not the learner's first zone: the rules of /settings apply to this entry point too —
                // 24 h between changes and the running quota day carried over — or re-running this step
                // would be a way around them (docs/12 §5.3). A refusal rolls the transaction back and
                // leaves the step as it was.
                $this->settings->update($fresh, $changes);
                $fresh->refresh();
            } else {
                $fresh->fill($changes);
            }
            if ($step === 'locale') {
                $fresh->meta = [...$fresh->meta, LearnerSettingsService::LOCALE_CHOSEN => true];
            }
            if ($step === 'timezone' || $step === 'reminder') {
                $this->settings->scheduleReminder($fresh);
            }

            $next = self::STEPS[array_search($step, self::STEPS, true) + 1] ?? self::DONE;
            $fresh->onboarding_state = $next;

            if ($next === self::DONE) {
                $completed = true;
                [$course, $via] = $this->completeCourse($fresh);
                $fresh->onboarding_completed_at = now();
                $this->settings->scheduleReminder($fresh);
            }

            $fresh->save();
        });

        $learner->refresh();

        $this->events->record(ProductEventName::OnboardingStepCompleted, ['step' => $step], learner: $learner);

        if ($completed) {
            $this->events->record(ProductEventName::OnboardingCompleted, [
                'goal' => $learner->goal,
                'level' => $learner->level,
                'locale' => $learner->interface_locale,
            ], learner: $learner);

            if ($course !== null) {
                $this->events->record(ProductEventName::CourseSelected, [
                    'course_id' => $course->id,
                    'via' => $via,
                ], learner: $learner);
            }
        }

        return $this->current($learner);
    }

    /** A zone change already happened through the settings, or its carried day is still running. */
    private function zoneChangeIsGuarded(Learner $learner): bool
    {
        return isset(($learner->meta ?? [])[LearnerSettingsService::TIMEZONE_CHANGED_AT_META])
            || $this->clock->hasActiveDayCarry($learner);
    }

    /**
     * `/settings` — change one onboarding value of a learner who has finished the onboarding.
     * Same whitelists as {@see self::apply()}, but no state machine; the change itself (and the
     * `next_reminder_at` it implies) is {@see LearnerSettingsService::update()}.
     *
     * @throws OnboardingException when the onboarding is not finished or $value is not allowed for $step
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
            'goal', 'level', 'timezone', 'reminder' => new OnboardingStep(
                $state,
                'onboarding.steps.'.$state,
                $this->options($state),
                match ($state) {
                    'level' => 3,
                    'timezone' => 1,
                    default => 2,
                },
            ),
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
            case 'goal':
                return array_map(
                    fn (string $goal): OnboardingOption => new OnboardingOption($goal, 'onboarding.options.goal.'.$goal),
                    self::GOALS,
                );
            case 'level':
                return array_map(
                    fn (string $level): OnboardingOption => new OnboardingOption($level, 'onboarding.options.level.'.$level),
                    [...self::LEVELS, self::LEVEL_UNKNOWN],
                );
            case 'timezone':
                return array_map(
                    fn (string $zone): OnboardingOption => new OnboardingOption(
                        $zone,
                        'onboarding.options.timezone.'.strtolower((string) substr((string) strrchr($zone, '/'), 1)),
                        ['offset' => $this->settings->offsetLabel($zone)],
                    ),
                    $this->settings->timezoneOptions(),
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
            'goal' => in_array($value, self::GOALS, true)
                ? ['goal' => $value]
                : throw OnboardingException::invalidValue($step),
            'level' => match (true) {
                $value === self::LEVEL_UNKNOWN => ['level' => 'A1'],
                in_array($value, self::LEVELS, true) => ['level' => $value],
                default => throw OnboardingException::invalidValue($step),
            },
            'timezone' => in_array($value, $this->settings->timezoneOptions(), true)
                ? ['timezone' => $value]
                : throw OnboardingException::invalidValue($step),
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
     * goal and level, else the default course.
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

        // The default course is a fallback only while it can be opened (published, not private).
        $course = $this->catalog->recommend($learner->goal, $learner->level)
            ?? $this->catalog->findAvailable($this->catalog->defaultCourse()->slug);
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
