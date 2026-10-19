<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learners\Exceptions\InvalidSettingException;
use App\Domain\Learners\Exceptions\TimezoneChangeTooSoonException;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * The place that changes a learner's settings AFTER the onboarding (docs/12 §3.8): the TWA
 * `PATCH /me/settings` and the bot's `/settings` (through {@see OnboardingService::applySetting()})
 * both end up in {@see update()}. The onboarding steps write the same columns themselves, in the
 * state machine of {@see OnboardingService}, and share {@see scheduleReminder()} and the
 * {@see LOCALE_CHOSEN} mark with this class.
 *
 * Fields and rules:
 *  - `interface_locale`  one of the supported locales; choosing one (even the current one) sets
 *                        `meta.locale_chosen`, after which {@see LearnerRegistrar} no longer derives
 *                        the language from Telegram's `language_code`;
 *  - `timezone`          any id PHP knows, also the legacy aliases (`DateTimeZone::ALL_WITH_BC`, e.g.
 *                        `Asia/Calcutta`), stored as given;
 *  - `reminder_time`     `HH:MM` on a {@see REMINDER_STEP_MINUTES}-minute step, or `null` = reminders off;
 *  - `daily_goal`        one of `config('learners.daily_goal_options')` (10 / 20 / 40);
 *  - `goal`, `level`     the onboarding whitelists.
 * Values are checked here again whatever the caller validated: strict types, no coercion.
 *
 * `update()` locks the learner row, writes everything in one transaction and recomputes
 * `next_reminder_at` when the time zone or the reminder time is part of the change (`NULL` while
 * reminders are off; the scheduler of 1.5b advances it after each send, docs/12 §7.5).
 *
 * A change of `timezone` (a value different from the current one) is allowed once per
 * {@see TIMEZONE_CHANGE_INTERVAL_HOURS} — otherwise {@see TimezoneChangeTooSoonException} and nothing
 * of the update is applied — and carries the learner's running local day into the new zone
 * ({@see LearnerClock::carryDayAcrossTimezoneChange()}), so the Free quota cannot be replayed by
 * hopping between zones (docs/12 §5.3). Both are written in the same transaction as the zone
 * (`learners.meta.timezone_changed_at`, `learners.meta.local_day_carry`), under the learner-row
 * lock the card picker also takes, so a concurrent pick sees either the old zone or the new zone
 * with its carry. The onboarding sets the first zone directly (OnboardingService::apply) and is
 * not subject to either rule — unless a zone change through the settings already happened (or its
 * carried day is still running): then the onboarding's zone step calls {@see update()} too.
 *
 * Lowering `daily_goal` to or below what is already done today completes today's goal at once
 * ({@see DailyStatsService::reconcileGoal()}) — in its own transaction AFTER the `learners` one has
 * committed, so `learners` and `learner_daily_stats` are never locked together (docs/06 §4.2; the
 * review transaction holds only the latter and never touches `learners`). The streak is credited
 * after that commit, as for any other goal.
 *
 * @phpstan-type SettingsPayload array{
 *   interface_locale: string,
 *   timezone: string,
 *   reminder_time: string|null,
 *   daily_goal: int,
 *   goal: string|null,
 *   level: string|null,
 *   timezone_change_available_at: string|null,
 *   active_course: array{slug: string, title: string}|null,
 *   options: array{
 *     locales: list<array{id: string, label: string}>,
 *     timezones: list<array{id: string, label: string}>,
 *     reminder_times: list<string>,
 *     daily_goals: list<int>
 *   }
 * }
 *
 * Nothing is cached per setting: the entitlement cache does not depend on them and the catalog
 * cache is per locale and public.
 */
final class LearnerSettingsService
{
    public const REMINDER_STEP_MINUTES = 15;

    /** Minimum time between two changes of `timezone` (docs/12 §5.3). */
    public const TIMEZONE_CHANGE_INTERVAL_HOURS = 24;

    /** `learners.meta` key: UTC instant of the last accepted change of `timezone` (not set by the onboarding). */
    public const TIMEZONE_CHANGED_AT_META = 'timezone_changed_at';

    /** `learners.meta` key: the learner picked the interface language (settings / onboarding) — Telegram's `language_code` stops deciding it. */
    public const LOCALE_CHOSEN = 'locale_chosen';

    public function __construct(
        private readonly LocaleResolver $locales,
        private readonly LearnerClock $clock,
        private readonly DailyStatsService $stats,
        private readonly CourseCatalogService $catalog,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  field => new value (a partial update; only the keys present are touched)
     *
     * @throws InvalidSettingException for an unknown field or a value the field does not accept
     * @throws TimezoneChangeTooSoonException when `timezone` differs from the current one but the last change is too recent
     */
    public function update(Learner $learner, array $changes): Learner
    {
        $values = $this->normalize($changes);
        if ($values === []) {
            return $learner;
        }

        $changed = [];

        DB::transaction(function () use ($learner, $values, &$changed): void {
            // `FOR NO KEY UPDATE` on PostgreSQL (as in CardPicker): the row is rewritten, but rows that
            // reference `learners` (daily stats, events, ...) can keep being inserted meanwhile.
            $lock = Learner::query()->whereKey($learner->id);
            /** @var Learner $fresh */
            $fresh = (DB::connection()->getDriverName() === 'pgsql' ? $lock->lock('for no key update') : $lock->lockForUpdate())->firstOrFail();

            foreach ($values as $field => $value) {
                $current = $field === 'reminder_time' ? $this->shortTime($fresh->reminder_time) : $fresh->getAttribute($field);
                if ($current !== $value) {
                    $changed[] = $field;
                }
            }

            if (in_array('timezone', $changed, true)) {
                $this->changeTimezone($fresh, CarbonImmutable::now());
            }

            $fresh->fill($values);
            if (array_key_exists('interface_locale', $values)) {
                $fresh->meta = [...$fresh->meta, self::LOCALE_CHOSEN => true];
            }
            if (array_key_exists('timezone', $values) || array_key_exists('reminder_time', $values)) {
                $this->scheduleReminder($fresh);
            }

            $fresh->save();
        });

        $learner->refresh();

        if ($changed !== []) {
            $this->events->record(ProductEventName::SettingsChanged, ['fields' => implode(',', $changed)], learner: $learner);
        }

        if (in_array('daily_goal', $changed, true)) {
            // The settings are committed: a failed reconcile (deadlock, timeout) must not turn the
            // request into a 500. The goal is then completed by the next grade (`recordReview` reads
            // the fresh `daily_goal`) or by the next change.
            try {
                $this->stats->reconcileGoal($learner);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $learner;
    }

    /**
     * What the TWA settings screen shows: the current values and the choices for each field,
     * labelled in the learner's language. A time zone / reminder time the learner already has but
     * the lists do not offer (set through the API) is added to the list, so the picker can show it.
     *
     * @return SettingsPayload
     */
    public function describe(Learner $learner): array
    {
        $locale = $this->locales->forLearner($learner);
        $reminder = $this->shortTime($learner->reminder_time);

        $zones = $this->timezoneOptions();
        if (! in_array($learner->timezone, $zones, true)) {
            $zones[] = $learner->timezone;
        }

        $times = $this->reminderPresets();
        if ($reminder !== null && ! in_array($reminder, $times, true)) {
            $times[] = $reminder;
            sort($times);
        }

        return [
            'interface_locale' => $learner->interface_locale,
            'timezone' => $learner->timezone,
            'reminder_time' => $reminder,
            'daily_goal' => $learner->daily_goal,
            'goal' => $learner->goal,
            'level' => $learner->level,
            'timezone_change_available_at' => $this->timezoneChangeAvailableAt($learner)?->utc()->toIso8601String(),
            'active_course' => $this->activeCourse($learner, $locale),
            'options' => [
                'locales' => array_map(
                    fn (string $id): array => ['id' => $id, 'label' => (string) config('localization.names.'.$id, $id)],
                    $this->locales->supported(),
                ),
                'timezones' => array_map(
                    fn (string $zone): array => ['id' => $zone, 'label' => $this->timezoneLabel($zone, $locale)],
                    $zones,
                ),
                'reminder_times' => $times,
                'daily_goals' => $this->dailyGoals(),
            ],
        ];
    }

    /**
     * Time zones offered in pickers (the bot's `/settings` and the TWA): `config('learners.timezone_options')`
     * limited to ids PHP also knows, so a typo in the config can never become a value.
     *
     * @return list<string>
     */
    public function timezoneOptions(): array
    {
        $known = $this->knownTimezones();

        return array_values(array_filter(
            array_map('strval', (array) config('learners.timezone_options', [])),
            fn (string $zone): bool => in_array($zone, $known, true),
        ));
    }

    /** @return list<string> every time zone id a learner may set (IANA ids and their legacy aliases) */
    public function knownTimezones(): array
    {
        return DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
    }

    /** @return list<int> */
    public function dailyGoals(): array
    {
        return array_values(array_map('intval', (array) config('learners.daily_goal_options', [10, 20, 40])));
    }

    /** @return list<string> the times the pickers offer (the bot's buttons); the API accepts any quarter of an hour */
    public function reminderPresets(): array
    {
        return array_values(OnboardingService::REMINDER_TIMES);
    }

    /**
     * When the learner may next change `timezone`: `null` = right now (never changed, or the interval has
     * passed). For the settings screen to say so before the learner tries.
     */
    public function timezoneChangeAvailableAt(Learner $learner, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $now ??= CarbonImmutable::now();
        $raw = ($learner->meta ?? [])[self::TIMEZONE_CHANGED_AT_META] ?? null;
        if (! is_string($raw)) {
            return null;
        }

        try {
            $availableAt = CarbonImmutable::parse($raw)->utc()->addHours(self::TIMEZONE_CHANGE_INTERVAL_HOURS);
        } catch (Throwable) {
            return null;
        }

        return $now->isBefore($availableAt) ? $availableAt : null;
    }

    /** `HH:MM` of a stored `time` (PostgreSQL returns `HH:MM:SS`); `null` stays `null`. */
    public function shortTime(?string $time): ?string
    {
        return $time === null || $time === '' ? null : substr($time, 0, 5);
    }

    /** "Ташкент (UTC+5)": the city name of the bot's strings when there is one, else the id; plus the current UTC offset. */
    public function timezoneLabel(string $zone, string $locale): string
    {
        $offset = $this->offsetLabel($zone);
        $key = 'onboarding.options.timezone.'.strtolower(substr((string) strrchr($zone, '/'), 1));

        return Lang::has($key, $locale, false)
            ? __($key, ['offset' => $offset], $locale)
            : "{$zone} ({$offset})";
    }

    public function offsetLabel(string $zone): string
    {
        $seconds = (new DateTimeZone($zone))->getOffset(new DateTimeImmutable('now'));
        $minutes = intdiv(abs($seconds) % 3600, 60);

        return 'UTC'.($seconds < 0 ? '-' : '+').intdiv(abs($seconds), 3600).($minutes > 0 ? sprintf(':%02d', $minutes) : '');
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, int|string|null>
     */
    private function normalize(array $changes): array
    {
        $values = [];

        foreach ($changes as $field => $value) {
            $values[$field] = match ($field) {
                'interface_locale' => is_string($value) && $this->locales->isSupported($value) ? $value : throw InvalidSettingException::invalid($field),
                'timezone' => is_string($value) && in_array($value, $this->knownTimezones(), true) ? $value : throw InvalidSettingException::invalid($field),
                'reminder_time' => $value === null || (is_string($value) && $this->isReminderTime($value)) ? $value : throw InvalidSettingException::invalid($field),
                'daily_goal' => is_int($value) && in_array($value, $this->dailyGoals(), true) ? $value : throw InvalidSettingException::invalid($field),
                'goal' => is_string($value) && in_array($value, OnboardingService::GOALS, true) ? $value : throw InvalidSettingException::invalid($field),
                'level' => is_string($value) && in_array($value, OnboardingService::LEVELS, true) ? $value : throw InvalidSettingException::invalid($field),
                default => throw InvalidSettingException::unknown((string) $field),
            };
        }

        return $values;
    }

    private function isReminderTime(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $m) === 1
            && (int) $m[2] % self::REMINDER_STEP_MINUTES === 0;
    }

    /**
     * The zone is about to change (the model still has the old one): enforce the interval and carry the
     * running day over. Both marks live in `meta` and are saved with the zone by the caller.
     *
     * @throws TimezoneChangeTooSoonException
     */
    private function changeTimezone(Learner $fresh, CarbonImmutable $now): void
    {
        $availableAt = $this->timezoneChangeAvailableAt($fresh, $now);
        if ($availableAt !== null) {
            throw new TimezoneChangeTooSoonException($availableAt);
        }

        $this->clock->carryDayAcrossTimezoneChange($fresh, $now);
        $fresh->meta = [...($fresh->meta ?? []), self::TIMEZONE_CHANGED_AT_META => $now->utc()->toIso8601String()];
    }

    /**
     * Sets `next_reminder_at` (not saved) for the learner's current time zone and `reminder_time`
     * (`NULL` = reminders are off). Shared with {@see OnboardingService}.
     */
    public function scheduleReminder(Learner $learner): void
    {
        $time = $learner->reminder_time;

        $learner->next_reminder_at = $time === null || $time === ''
            ? null
            : Carbon::instance($this->clock->nextOccurrenceUtc($learner, $time, CarbonImmutable::now()));
    }

    /** @return array{slug: string, title: string}|null null while the learner has no course or it is no longer available */
    private function activeCourse(Learner $learner, string $locale): ?array
    {
        if ($learner->active_course_id === null) {
            return null;
        }

        $course = $learner->activeCourse;
        if ($course === null || ! $this->catalog->isAvailable($course)) {
            return null;
        }

        return ['slug' => $course->slug, 'title' => $this->catalog->title($course, $locale)];
    }
}
