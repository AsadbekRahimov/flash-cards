<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Domain\Learners\Exceptions\DailyGoalLimitException;
use App\Domain\Learners\Exceptions\InvalidSettingException;
use App\Domain\Learners\Exceptions\ProEmojiProRequiredException;
use App\Domain\Learners\Exceptions\RegionChangeTooSoonException;
use App\Domain\Learners\Exceptions\TimezoneChangeTooSoonException;
use App\Domain\Learners\Exceptions\TimezoneRevertUnavailableException;
use App\Domain\Learners\Support\Regions;
use App\Domain\Learning\DTO\LearnerExerciseSettings;
use App\Domain\Learning\Services\SchedulerResolver;
use App\Domain\Learning\Support\LadderPolicy;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
 *                        `Asia/Calcutta`), stored as given. No learner-facing entry point changes it any more
 *                        (owner's decision of 16.11.2026: new learners get `learners.default_timezone`); the rule
 *                        and the machinery below stay for the code that still calls it (undo, operations);
 *  - `reminder_time`     `HH:MM` on a {@see REMINDER_STEP_MINUTES}-minute step, or `null` = reminders off;
 *  - `daily_goal`        one of `config('learners.daily_goal_options')` (10 / 20 / 40), and not above the limit of the plan
 *                        (docs/34 §2б.3; Free: 10) - else {@see DailyGoalLimitException} (403 `daily_goal_pro_required`) and nothing
 *                        of the update is applied. Any accepted value (even the current one) clears `daily_goal_before_limit`: a goal
 *                        chosen by hand is not replaced by the one remembered before the automatic lowering ({@see DailyGoalLimiter});
 *  - `goal`, `level`     the onboarding whitelists (`goal`: the goals enabled in `learners.goals`);
 *  - `leagues_opt_out`   bool, kept in `learners.meta` (docs/13 §7.3): `true` = not placed in league groups any more.
 *  - `exercises`         `{meaning, reverse, audio, spelling, gap_fill}` booleans (a partial object: only the keys
 *                        given change), stored in `learners.meta.exercises` (docs/13 §8.2); flash cards
 *                        cannot be switched off.
 *  - `retention_mode`    `calm` / `normal` / `intense` — the FSRS target retention (docs/06 §12.8), kept in
 *                        `learners.meta.retention_mode`; accepted for every learner, applied only to those scheduled by FSRS.
 *  - `region_code`       a code of {@see Regions} or null ("not given"); the rules (30 days between choices, the reset, the undo)
 *                        are {@see LearnerRegionService::applyLocked()} - {@see RegionChangeTooSoonException} (422 `region_change_too_soon`)
 *                        and nothing of the update is applied; `region.set` after the commit (`$regionVia`: settings / prompt).
 *  - `pro_emoji`         a code of `config/pro_emoji.php` or null (docs/31 §5а); a code needs a live Pro - else
 *                        {@see ProEmojiProRequiredException} (403 `pro_emoji_pro_required`) and nothing is applied; null is always accepted.
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
 * A change of the zone can be taken back within {@see TIMEZONE_UNDO_MINUTES} ({@see revertTimezone()}):
 * the change saves what it replaced in `meta.timezone_change_undo` (the previous zone, the moment of the
 * change and the previous `timezone_changed_at` / `local_day_carry`), and the undo puts exactly that back —
 * no 24 h penalty and no later quota window than without the change. A newer change overwrites the snapshot.
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
 *   leagues_opt_out: bool,
 *   timezone_change_available_at: string|null,
 *   timezone_undo_available_until: string|null,
 *   active_course: array{slug: string, title: string}|null,
 *   exercises: array{meaning: bool, reverse: bool, audio: bool, spelling: bool, gap_fill: bool},
 *   scheduler: string,
 *   retention_mode: string,
 *   region_code: string|null,
 *   region_change_available_at: string|null,
 *   region_undo_available_until: string|null,
 *   region_prompt: bool,
 *   pro_emoji_choice: string|null,
 *   options: array{
 *     locales: list<array{id: string, label: string}>,
 *     reminder_times: list<string>,
 *     daily_goals: list<int>,
 *     daily_goal_max: int|null,
 *     retention_modes: list<string>,
 *     regions: list<array{code: string, name: string}>,
 *     pro_emojis: list<array{code: string, emoji: string}>,
 *     pro_emoji_locked: bool
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

    /** How long after a change of `timezone` it can be taken back (docs/13 §9, 2R.7). */
    public const TIMEZONE_UNDO_MINUTES = 10;

    /** `learners.meta` key: what {@see revertTimezone()} needs — `{timezone, changed_at, previous_changed_at, previous_day_carry}`. */
    public const TIMEZONE_UNDO_META = 'timezone_change_undo';

    /** `learners.meta` key: the learner picked the interface language (settings / onboarding) — Telegram's `language_code` stops deciding it. */
    public const LOCALE_CHOSEN = 'locale_chosen';

    /** @var list<string>|null */
    private ?array $knownTimezones = null;

    public function __construct(
        private readonly LocaleResolver $locales,
        private readonly LearnerClock $clock,
        private readonly DailyStatsService $stats,
        private readonly CourseCatalogService $catalog,
        private readonly ProductEventRecorder $events,
        private readonly SchedulerResolver $schedulers,
        private readonly MonthlyRatingService $monthly,
        private readonly EntitlementResolver $entitlements,
        private readonly DailyGoalLimiter $goals,
        private readonly LearnerRegionService $regions,
        private readonly ProEmojiService $emoji,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  field => new value (a partial update; only the keys present are touched)
     *
     * @throws InvalidSettingException for an unknown field or a value the field does not accept
     * @throws TimezoneChangeTooSoonException when `timezone` differs from the current one but the last change is too recent
     * @throws DailyGoalLimitException when `daily_goal` is above the limit of the learner's plan (Free: 10)
     * @throws RegionChangeTooSoonException when `region_code` changes before 30 days have passed since the last choice
     * @throws ProEmojiProRequiredException when a learner without Pro chooses `pro_emoji`
     *
     * @param  string  $regionVia  where a change of `region_code` comes from, for `region.set`: `settings` or `prompt`
     */
    public function update(Learner $learner, array $changes, string $regionVia = LearnerRegionService::VIA_SETTINGS): Learner
    {
        $values = $this->normalize($changes);
        if ($values === []) {
            return $learner;
        }

        // The plan is resolved before the transaction and only when the goal or an emoji is part of the change (docs/34 §2б.3,
        // docs/31 §5а). A plan that ends between this check and the write leaves a goal above the limit - the next enforce() lowers
        // it - or an emoji that is simply not shown.
        if (is_int($values['daily_goal'] ?? null) || is_string($values['pro_emoji'] ?? null)) {
            $entitlement = $this->entitlements->for($learner);
            if (is_int($values['daily_goal'] ?? null)) {
                $this->goals->assertAllowed($values['daily_goal'], $entitlement);
            }
            if (is_string($values['pro_emoji'] ?? null)) {
                $this->emoji->assertAllowed($values['pro_emoji'], $entitlement);
            }
        }

        $changed = [];
        $remindersDisabled = false;
        $regionChange = null;

        DB::transaction(function () use ($learner, $values, &$changed, &$remindersDisabled, &$regionChange): void {
            // `FOR NO KEY UPDATE` on PostgreSQL (as in CardPicker): the row is rewritten, but rows that
            // reference `learners` (daily stats, events, ...) can keep being inserted meanwhile.
            $lock = Learner::query()->whereKey($learner->id);
            /** @var Learner $fresh */
            $fresh = (DB::connection()->getDriverName() === 'pgsql' ? $lock->lock('for no key update') : $lock->lockForUpdate())->firstOrFail();

            $exercises = null;
            if (is_array($values['exercises'] ?? null)) {
                /** @var array<string, bool> $patch */
                $patch = $values['exercises'];
                $current = LearnerExerciseSettings::fromLearner($fresh)->toArray();
                $exercises = [...$current, ...$patch];
                if ($exercises !== $current) {
                    $changed[] = 'exercises';
                }
                unset($values['exercises']);
            }

            foreach ($values as $field => $value) {
                $current = match ($field) {
                    'reminder_time' => $this->shortTime($fresh->reminder_time),
                    'leagues_opt_out' => (bool) (($fresh->meta ?? [])[LeagueService::OPT_OUT_META] ?? false),
                    'retention_mode' => SchedulerResolver::retentionMode($fresh) ?? 'normal',
                    default => $fresh->getAttribute($field),
                };
                if ($current !== $value) {
                    $changed[] = $field;
                }
            }
            // A time → null (not a change of the time): the guardrail of the reminder experiment (audit 2026-11-18).
            $remindersDisabled = array_key_exists('reminder_time', $values) && $values['reminder_time'] === null
                && $this->shortTime($fresh->reminder_time) !== null;

            if (array_key_exists('leagues_opt_out', $values)) {
                $fresh->meta = [...($fresh->meta ?? []), LeagueService::OPT_OUT_META => (bool) $values['leagues_opt_out']];
                unset($values['leagues_opt_out']);
            }

            if (is_string($values['retention_mode'] ?? null)) {
                $fresh->meta = [...($fresh->meta ?? []), LadderPolicy::RETENTION_MODE_META => $values['retention_mode']];
                unset($values['retention_mode']);
            }

            if (in_array('timezone', $changed, true)) {
                $this->changeTimezone($fresh, CarbonImmutable::now());
            }

            if (array_key_exists('region_code', $values)) {
                $region = $values['region_code'];
                $regionChange = $this->regions->applyLocked($fresh, is_string($region) ? $region : null, CarbonImmutable::now());
                unset($values['region_code']);
            }

            if (array_key_exists('pro_emoji', $values)) {
                $emoji = $values['pro_emoji'];
                $fresh->pro_emoji = is_string($emoji) ? $emoji : null;
                unset($values['pro_emoji']);
            }

            $fresh->fill($values);
            if ($exercises !== null) {
                $fresh->meta = [...$fresh->meta, LearnerExerciseSettings::META_KEY => $exercises];
            }
            if (array_key_exists('interface_locale', $values)) {
                $fresh->meta = [...$fresh->meta, self::LOCALE_CHOSEN => true];
            }
            if (array_key_exists('daily_goal', $values)) {
                // A goal chosen by hand: the one remembered before the automatic lowering does not come back with Pro.
                $fresh->daily_goal_before_limit = null;
            }
            if (array_key_exists('timezone', $values) || array_key_exists('reminder_time', $values)) {
                $this->scheduleReminder($fresh);
            }

            $fresh->save();
        });

        $learner->refresh();

        if (in_array('leagues_opt_out', $changed, true)) {
            $this->monthly->participationChanged($learner);
        }

        if ($changed !== []) {
            $this->events->record(ProductEventName::SettingsChanged, ['fields' => implode(',', $changed)], learner: $learner);
        }
        if ($regionChange !== null) {
            $this->regions->recordChange($learner, $regionChange, $regionVia);
        }
        if ($remindersDisabled) {
            $this->events->record(ProductEventName::RemindersDisabled, ['via' => 'settings'], learner: $learner);
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
     * `$activeCourse` - the `active_course` block when the caller already has it (the `/me` snapshot, docs/19 K9): the
     * course is not read then. Pass `null` only through the snapshot; without the argument the course is read here.
     *
     * `$entitlement` - the learner's plan when the caller has it (the `/me` snapshot); resolved here otherwise. With it the goal is
     * brought in line with the plan first ({@see DailyGoalLimiter::enforce()}: no statement when nothing is to change), and
     * `options.daily_goal_max` tells the screen which goals to lock (null = none).
     *
     * @param  array{slug: string, title: string}|null  $activeCourse
     * @return SettingsPayload
     */
    public function describe(Learner $learner, array|null|false $activeCourse = false, ?Entitlement $entitlement = null): array
    {
        $entitlement ??= $this->entitlements->for($learner);
        $this->goals->enforce($learner, $entitlement, DailyGoalLimiter::VIA_SETTINGS);

        $locale = $this->locales->forLearner($learner);
        $reminder = $this->shortTime($learner->reminder_time);

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
            'leagues_opt_out' => (bool) (($learner->meta ?? [])[LeagueService::OPT_OUT_META] ?? false),
            'timezone_change_available_at' => $this->timezoneChangeAvailableAt($learner)?->utc()->toIso8601String(),
            'timezone_undo_available_until' => $this->timezoneUndoAvailableUntil($learner)?->toIso8601String(),
            'active_course' => $activeCourse === false ? $this->activeCourseBlock($learner, $locale) : $activeCourse,
            'exercises' => LearnerExerciseSettings::fromLearner($learner)->toArray(),
            // The SRS arm of the learner and the FSRS retention mode (docs/06 §12.8): the screen shows the dial on FSRS only.
            'scheduler' => $this->schedulers->kindFor($learner)->value,
            'retention_mode' => SchedulerResolver::retentionMode($learner) ?? 'normal',
            // The region (docs/35 §2.3): when another one can be chosen, until when the last change can be taken back, and whether the
            // card "Choose your region" of the Rating tab is shown.
            'region_code' => $learner->region_code,
            'region_change_available_at' => $this->regions->changeAvailableAt($learner)?->utc()->toIso8601String(),
            'region_undo_available_until' => $this->regions->undoAvailableUntil($learner)?->utc()->toIso8601String(),
            'region_prompt' => $this->regions->promptVisible($learner),
            // The emoji of Pro (docs/31 §5а): the stored choice, Pro or not - a Free learner sees it as a plus of Pro.
            'pro_emoji_choice' => $this->emoji->choice($learner),
            'options' => [
                'locales' => array_map(
                    fn (string $id): array => ['id' => $id, 'label' => (string) config('localization.names.'.$id, $id)],
                    $this->locales->supported(),
                ),
                'reminder_times' => $times,
                'daily_goals' => $this->dailyGoals(),
                'daily_goal_max' => $this->goals->cap($entitlement),
                'retention_modes' => LadderPolicy::RETENTION_MODES,
                // The 14 regions of Uzbekistan in the order of the language, then `other`.
                'regions' => array_map(
                    static fn (string $code): array => ['code' => $code, 'name' => Regions::name($code, $locale)],
                    [...Regions::ordered($locale), Regions::OTHER],
                ),
                'pro_emojis' => $this->emoji->options(),
                'pro_emoji_locked' => ! $this->emoji->isAllowed($entitlement),
            ],
        ];
    }

    /** @return list<string> every time zone id a learner may set (IANA ids and their legacy aliases) */
    public function knownTimezones(): array
    {
        // ~600 ids, read by the pickers, the validation and the undo snapshot of one request: listed once per instance.
        return $this->knownTimezones ??= DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
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

    /**
     * Takes back the last change of `timezone` if it is at most {@see TIMEZONE_UNDO_MINUTES} old: the previous
     * zone, `timezone_changed_at` and `local_day_carry` come back exactly as they were (a mark that did not
     * exist is removed), so the learner pays no 24 h wait and the quota window is the one there would be
     * without the change. The snapshot is consumed: a second undo is refused. `streak_freezes*` are not
     * touched — a refill is tied to the week, which only moves forward.
     *
     * @throws TimezoneRevertUnavailableException no change to take back, or the 10 minutes have passed
     */
    public function revertTimezone(Learner $learner): Learner
    {
        DB::transaction(function () use ($learner): void {
            $lock = Learner::query()->whereKey($learner->id);
            /** @var Learner $fresh */
            $fresh = (DB::connection()->getDriverName() === 'pgsql' ? $lock->lock('for no key update') : $lock->lockForUpdate())->firstOrFail();

            $undo = $this->timezoneUndo($fresh);
            $now = CarbonImmutable::now();
            if ($undo === null || ! $now->isBefore($undo['changed_at']->addMinutes(self::TIMEZONE_UNDO_MINUTES))) {
                throw new TimezoneRevertUnavailableException;
            }

            $meta = $fresh->meta ?? [];
            unset($meta[self::TIMEZONE_UNDO_META], $meta[self::TIMEZONE_CHANGED_AT_META], $meta[LearnerClock::DAY_CARRY_META]);
            if ($undo['previous_changed_at'] !== null) {
                $meta[self::TIMEZONE_CHANGED_AT_META] = $undo['previous_changed_at'];
            }
            if ($undo['previous_day_carry'] !== null) {
                $meta[LearnerClock::DAY_CARRY_META] = $undo['previous_day_carry'];
            }

            $fresh->timezone = $undo['timezone'];
            $fresh->meta = $meta;
            $this->scheduleReminder($fresh);
            $fresh->save();
        });

        $learner->refresh();
        $this->events->record(ProductEventName::SettingsChanged, ['fields' => 'timezone'], learner: $learner);

        return $learner;
    }

    /** Until when the last change of `timezone` can be taken back; `null` = it cannot (never changed, or too late). */
    public function timezoneUndoAvailableUntil(Learner $learner, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $undo = $this->timezoneUndo($learner);
        if ($undo === null) {
            return null;
        }

        $until = $undo['changed_at']->addMinutes(self::TIMEZONE_UNDO_MINUTES);

        return ($now ?? CarbonImmutable::now())->isBefore($until) ? $until : null;
    }

    /**
     * The well-formed undo snapshot of `learners.meta`, or null.
     *
     * @return array{timezone: string, changed_at: CarbonImmutable, previous_changed_at: string|null, previous_day_carry: array<mixed>|null}|null
     */
    private function timezoneUndo(Learner $learner): ?array
    {
        $raw = ($learner->meta ?? [])[self::TIMEZONE_UNDO_META] ?? null;
        if (! is_array($raw) || ! is_string($raw['timezone'] ?? null) || ! is_string($raw['changed_at'] ?? null)
            || ! in_array($raw['timezone'], $this->knownTimezones(), true)) {
            return null;
        }

        $previousChangedAt = $raw['previous_changed_at'] ?? null;
        $previousCarry = $raw['previous_day_carry'] ?? null;
        if (($previousChangedAt !== null && ! is_string($previousChangedAt)) || ($previousCarry !== null && ! is_array($previousCarry))) {
            return null;
        }

        try {
            $changedAt = CarbonImmutable::parse($raw['changed_at'])->utc();
        } catch (Throwable) {
            return null;
        }

        return [
            'timezone' => $raw['timezone'],
            'changed_at' => $changedAt,
            'previous_changed_at' => $previousChangedAt,
            'previous_day_carry' => $previousCarry,
        ];
    }

    /** `HH:MM` of a stored `time` (PostgreSQL returns `HH:MM:SS`); `null` stays `null`. */
    public function shortTime(?string $time): ?string
    {
        return $time === null || $time === '' ? null : substr($time, 0, 5);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, int|string|bool|array<string, bool>|null>
     */
    private function normalize(array $changes): array
    {
        $values = [];

        foreach ($changes as $field => $value) {
            $values[$field] = match ($field) {
                'exercises' => is_array($value) ? $this->normalizeExercises($value) : throw InvalidSettingException::invalid($field),
                'interface_locale' => is_string($value) && $this->locales->isSupported($value) ? $value : throw InvalidSettingException::invalid($field),
                'timezone' => is_string($value) && in_array($value, $this->knownTimezones(), true) ? $value : throw InvalidSettingException::invalid($field),
                'reminder_time' => $value === null || (is_string($value) && $this->isReminderTime($value)) ? $value : throw InvalidSettingException::invalid($field),
                'daily_goal' => is_int($value) && in_array($value, $this->dailyGoals(), true) ? $value : throw InvalidSettingException::invalid($field),
                'goal' => is_string($value) && in_array($value, OnboardingService::availableGoals(), true) ? $value : throw InvalidSettingException::invalid($field),
                'level' => is_string($value) && in_array($value, OnboardingService::LEVELS, true) ? $value : throw InvalidSettingException::invalid($field),
                'leagues_opt_out' => is_bool($value) ? $value : throw InvalidSettingException::invalid($field),
                'retention_mode' => is_string($value) && in_array($value, LadderPolicy::RETENTION_MODES, true) ? $value : throw InvalidSettingException::invalid($field),
                'region_code' => $value === null || (is_string($value) && Regions::isValid($value)) ? $value : throw InvalidSettingException::invalid($field),
                'pro_emoji' => $value === null || (is_string($value) && $this->emoji->isValid($value)) ? $value : throw InvalidSettingException::invalid($field),
                default => throw InvalidSettingException::unknown((string) $field),
            };
        }

        return $values;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, bool>
     */
    private function normalizeExercises(array $value): array
    {
        $exercises = [];
        foreach ($value as $key => $enabled) {
            if (! is_string($key) || ! in_array($key, LearnerExerciseSettings::KEYS, true) || ! is_bool($enabled)) {
                throw InvalidSettingException::invalid('exercises');
            }
            $exercises[$key] = $enabled;
        }

        return $exercises;
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

        $before = $fresh->meta ?? [];
        $undo = [
            'timezone' => $fresh->timezone,
            'changed_at' => $now->utc()->toIso8601String(),
            'previous_changed_at' => $before[self::TIMEZONE_CHANGED_AT_META] ?? null,
            'previous_day_carry' => $before[LearnerClock::DAY_CARRY_META] ?? null,
        ];

        $this->clock->carryDayAcrossTimezoneChange($fresh, $now);
        $fresh->meta = [
            ...($fresh->meta ?? []),
            self::TIMEZONE_CHANGED_AT_META => $now->utc()->toIso8601String(),
            self::TIMEZONE_UNDO_META => $undo,
        ];
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

    /**
     * The `active_course` block of the settings: null while the learner has no course or it is no longer available. One
     * read of the course (`/me` keeps it in its snapshot).
     *
     * @return array{slug: string, title: string}|null
     */
    public function activeCourseBlock(Learner $learner, string $locale): ?array
    {
        if ($learner->active_course_id === null) {
            return null;
        }

        $course = $learner->activeCourse;
        if ($course === null || ! $this->catalog->isAvailable($course, $learner)) {
            return null;
        }

        return ['slug' => $course->slug, 'title' => $this->catalog->title($course, $locale)];
    }
}
