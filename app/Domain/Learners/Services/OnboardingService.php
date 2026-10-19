<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learners\DTO\OnboardingOption;
use App\Domain\Learners\DTO\OnboardingStep;
use App\Domain\Learners\DTO\StartPayload;
use App\Domain\Learners\Exceptions\OnboardingException;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Course;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
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

    public function __construct(
        private readonly LocaleResolver $locales,
        private readonly CourseCatalogService $catalog,
        private readonly ProductEventRecorder $events,
        private readonly LearnerClock $clock,
    ) {}

    /**
     * `/start` of a learner who has not finished the onboarding: `new` moves to the first step
     * (`onboarding.started`); a learner in the middle of it just gets their current step again.
     * A `c_<slug>` payload is remembered and picks the active course when the onboarding ends.
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

            $fresh->fill($changes);
            if ($step === 'timezone' || $step === 'reminder') {
                $this->scheduleReminder($fresh);
            }

            $next = self::STEPS[array_search($step, self::STEPS, true) + 1] ?? self::DONE;
            $fresh->onboarding_state = $next;

            if ($next === self::DONE) {
                $completed = true;
                [$course, $via] = $this->completeCourse($fresh);
                $fresh->onboarding_completed_at = now();
                $this->scheduleReminder($fresh);
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

    /**
     * `/settings` — change one onboarding value of a learner who has finished the onboarding.
     * Same whitelists as {@see self::apply()}, but no state machine.
     *
     * @throws OnboardingException when the onboarding is not finished or $value is not allowed for $step
     */
    public function applySetting(Learner $learner, string $step, string $value): void
    {
        $changes = $this->changes($step, $value);

        DB::transaction(function () use ($learner, $step, $changes): void {
            $fresh = $this->lock($learner);
            if ($fresh->onboarding_state !== self::DONE) {
                throw OnboardingException::staleStep($step);
            }

            $fresh->fill($changes);
            if ($step === 'timezone' || $step === 'reminder') {
                $this->scheduleReminder($fresh);
            }

            $fresh->save();
        });

        $learner->refresh();
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
                        ['offset' => $this->offsetLabel($zone)],
                    ),
                    $this->timezones(),
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
            'timezone' => in_array($value, $this->timezones(), true)
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
     * Offered time zones that PHP also knows (a typo in the config can never become a value).
     *
     * @return list<string>
     */
    private function timezones(): array
    {
        $known = DateTimeZone::listIdentifiers();

        return array_values(array_filter(
            array_map('strval', (array) config('learners.timezone_options', [])),
            fn (string $zone): bool => in_array($zone, $known, true),
        ));
    }

    private function offsetLabel(string $zone): string
    {
        $seconds = (new DateTimeZone($zone))->getOffset(new DateTimeImmutable('now'));
        $minutes = intdiv(abs($seconds) % 3600, 60);

        return 'UTC'.($seconds < 0 ? '-' : '+').intdiv(abs($seconds), 3600).($minutes > 0 ? sprintf(':%02d', $minutes) : '');
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

    /** `next_reminder_at` for the learner's current time zone and `reminder_time` (`NULL` = reminders are off). */
    private function scheduleReminder(Learner $learner): void
    {
        $time = $learner->reminder_time;

        $learner->next_reminder_at = $time === null || $time === ''
            ? null
            : Carbon::instance($this->clock->nextOccurrenceUtc($learner, $time, CarbonImmutable::now()));
    }

    private function lock(Learner $learner): Learner
    {
        return Learner::query()->whereKey($learner->id)->lockForUpdate()->firstOrFail();
    }
}
