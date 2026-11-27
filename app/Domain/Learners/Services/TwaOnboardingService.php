<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Content\DTO\CatalogCourse;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Support\DisplayName;
use App\Domain\Learners\DTO\TwaOnboardingCompletion;
use App\Domain\Learners\DTO\TwaOnboardingState;
use App\Domain\Learners\Exceptions\DailyGoalLimitException;
use App\Domain\Learners\Exceptions\InvalidSettingException;
use App\Domain\Learners\Exceptions\TwaOnboardingException;
use App\Domain\Learners\Support\OnboardingChoices;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Course;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The onboarding of the Mini App, O1-O8 (docs/40 §2.1, docs/04 A.24): a flow of its own over the columns the bot's onboarding writes,
 * not a second state machine. `learners.onboarding_state` is the bot's and is not extended; the Mini App keeps where it is in
 * `meta.twa_onboarding` ({@see OnboardingChoices}) and ends in the same place as the bot: {@see OnboardingService::completeExternally()}.
 *
 * Every step is saved at once, in any order (the learner went "Back" and changed an answer), under the learner's row lock:
 *  - `name` / `age` / `goals` - their own transaction (`FOR NO KEY UPDATE` on PostgreSQL, as in LearnerSettingsService): the column and
 *    the progress in one UPDATE;
 *  - `level` / `daily_goal` / `reminder` - {@see LearnerSettingsService::update()} (its whitelists, the limit of the goal of Free,
 *    `next_reminder_at`, the reconcile of today's goal), with the state check and the progress written under ITS lock, in its UPDATE.
 * A step of a learner who is `done` (the bot, another device) is refused with 409 and changes nothing. The first saved step of a learner
 * the bot never started (`new`) records `onboarding.started {channel: mini_app}`; a step records `onboarding.step_completed` the first
 * time it is saved and then only when its value changes, so the same request twice gives one event.
 *
 * Nothing is cached per learner: the reads are the learner (already loaded by the auth), the cached catalog and the cached plan.
 */
final class TwaOnboardingService
{
    public function __construct(
        private readonly OnboardingService $onboarding,
        private readonly LearnerSettingsService $settings,
        private readonly CourseCatalogService $catalog,
        private readonly LocaleResolver $locales,
        private readonly EntitlementResolver $entitlements,
        private readonly DailyGoalLimiter $goals,
        private readonly ProductEventRecorder $events,
    ) {}

    /** The state of the onboarding from the loaded row, the cached catalog and the cached plan - no statement when both are warm. */
    public function state(Learner $learner): TwaOnboardingState
    {
        $locale = $this->locales->forLearner($learner);
        $levels = $this->levelOptions($locale);

        return new TwaOnboardingState(
            completed: $learner->onboarding_state === OnboardingService::DONE,
            step: OnboardingChoices::resumeStep($learner),
            values: [
                'display_name' => $learner->display_name,
                'first_name' => $learner->first_name,
                'age_range' => $learner->age_range,
                'goals' => array_values(array_filter($learner->goals ?? [], 'is_string')),
                'level' => $learner->level,
                'course' => $this->chosenCourse($learner, $levels),
                'daily_goal' => (int) $learner->daily_goal,
                'reminder' => [
                    'enabled' => $this->settings->shortTime($learner->reminder_time) !== null,
                    'time' => $this->settings->shortTime($learner->reminder_time),
                ],
            ],
            options: [
                'age_ranges' => OnboardingChoices::AGE_RANGES,
                'goals' => OnboardingChoices::GOALS,
                'levels' => array_map(static function (array $option): array {
                    unset($option['course_id']);

                    return $option;
                }, $levels),
                'daily_goals' => $this->settings->dailyGoals(),
                'daily_goal_max' => $this->goals->cap($this->entitlements->for($learner)),
                'recommended_daily_goal' => OnboardingChoices::RECOMMENDED_DAILY_GOAL,
                'reminder_times' => OnboardingChoices::REMINDER_TIMES,
                'reminder_step_minutes' => LearnerSettingsService::REMINDER_STEP_MINUTES,
            ],
        );
    }

    /**
     * Saves one step (`$input` - the validated body of the step, see OnboardingStepRequest) and returns the new state.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws TwaOnboardingException 409 `onboarding_completed`; 422 `display_name_invalid {reason}`
     * @throws InvalidSettingException a value outside the whitelist of the step (422)
     * @throws DailyGoalLimitException a goal of the day above the limit of the plan (403 `daily_goal_pro_required {max}`)
     */
    public function saveStep(Learner $learner, string $step, array $input): TwaOnboardingState
    {
        // `done` is terminal: the loaded row is enough to refuse before anything else (the plan, the catalog); the lock checks again.
        if ($learner->onboarding_state === OnboardingService::DONE) {
            throw TwaOnboardingException::completed();
        }

        match ($step) {
            'name' => $this->saveOwn($learner, $step, ['display_name' => $this->displayName($input['display_name'] ?? null)]),
            'age' => $this->saveOwn($learner, $step, ['age_range' => $this->oneOf($input['age_range'] ?? null, OnboardingChoices::AGE_RANGES, 'age_range')]),
            'goals' => $this->saveOwn($learner, $step, ['goals' => $this->goalList($input['goals'] ?? null)]),
            'level' => $this->saveLevel($learner, $input),
            'daily_goal' => $this->saveSetting($learner, $step, ['daily_goal' => $input['daily_goal'] ?? null]),
            'reminder' => $this->saveReminder($learner, $input),
            default => throw InvalidSettingException::unknown($step),
        };

        return $this->state($learner);
    }

    /**
     * "Start the first lesson" on the summary (O8): ends the onboarding through the bot's own completion and returns the learner's
     * active course and the next lesson in it. Idempotent: a learner already `done` gets the same answer and no event.
     *
     * @throws TwaOnboardingException 422 `onboarding_incomplete {missing: [level]}` before the level is chosen
     */
    public function complete(Learner $learner): TwaOnboardingCompletion
    {
        $course = null;
        if ($learner->onboarding_state !== OnboardingService::DONE) {
            // Nothing ever clears `level`, so the loaded row is as good as the locked one here.
            if ($learner->level === null) {
                throw TwaOnboardingException::incomplete(['level']);
            }
            $course = $this->onboarding->completeExternally($learner, OnboardingChoices::CHANNEL)['course'];
        }

        // The course kept from before (a `c_<slug>` link, one chosen in the catalog) or the one of a parallel completion: the cached row.
        if ($course === null && $learner->active_course_id !== null) {
            $course = $this->catalog->cachedCourse($learner->active_course_id);
        }
        if ($course === null || $course->id !== $learner->active_course_id || ! $this->catalog->isAvailable($course, $learner)) {
            return new TwaOnboardingCompletion(null, null);
        }

        $locale = $this->locales->forLearner($learner);

        return new TwaOnboardingCompletion(
            ['slug' => $course->slug, 'title' => $this->catalog->title($course, $locale)],
            $this->catalog->todayCourse($course, $learner)->nextLesson?->id,
        );
    }

    /**
     * The courses offered on the level screen (O5): for every level the course the onboarding would give for it
     * ({@see CourseCatalogService::recommendFromCatalog()} for the general goal - never a course of phrases, docs/35 §3.3), the levels
     * leading to the same course merged into one row ("A1–A2"), in the order of the levels. `title` and `stages_count` are the
     * catalog's in `$locale`; `course_id` is internal (not in the API).
     *
     * @return list<array{level: string, levels: list<string>, course_slug: string, title: string, level_label: string, stages_count: int|null, course_id: int}>
     */
    private function levelOptions(string $locale): array
    {
        $catalog = $this->catalog->published($this->locales->default());
        $localized = [];
        foreach ($locale === $this->locales->default() ? $catalog : $this->catalog->published($locale) as $course) {
            $localized[$course->id] = $course;
        }

        /** @var array<string, array{course: CatalogCourse, levels: non-empty-list<string>}> $bySlug */
        $bySlug = [];
        foreach (OnboardingService::LEVELS as $level) {
            $course = $this->catalog->recommendFromCatalog(OnboardingService::GENERAL_GOAL, $level, $catalog);
            if ($course === null) {
                continue;
            }
            if (isset($bySlug[$course->slug])) {
                $bySlug[$course->slug]['levels'][] = $level;
            } else {
                $bySlug[$course->slug] = ['course' => $localized[$course->id] ?? $course, 'levels' => [$level]];
            }
        }

        $options = [];
        foreach ($bySlug as $slug => $row) {
            $options[] = [
                'level' => $row['levels'][0],
                'levels' => $row['levels'],
                'course_slug' => (string) $slug,
                'title' => $row['course']->title,
                'level_label' => self::levelLabel($row['levels']),
                'stages_count' => $row['course']->stagesCount,
                'course_id' => $row['course']->id,
            ];
        }

        return $options;
    }

    /**
     * "B1", "A1–A2" for neighbouring levels, "A1, C1" for levels that are not.
     *
     * @param  non-empty-list<string>  $levels
     */
    private static function levelLabel(array $levels): string
    {
        $positions = array_map(static fn (string $level): int => (int) array_search($level, OnboardingService::LEVELS, true), $levels);
        $contiguous = max($positions) - min($positions) === count($levels) - 1;

        return count($levels) === 1 ? $levels[0] : ($contiguous ? $levels[0].'–'.$levels[count($levels) - 1] : implode(', ', $levels));
    }

    /**
     * The course shown on the summary: the one chosen on the level screen, else the one of the learner's level (a level chosen in the bot).
     *
     * @param  list<array{level: string, levels: list<string>, course_slug: string, title: string, level_label: string, stages_count: int|null, course_id: int}>  $levels
     * @return array{slug: string, title: string, level_label: string, stages_count: int|null}|null
     */
    private function chosenCourse(Learner $learner, array $levels): ?array
    {
        $slug = OnboardingChoices::progress($learner)['course_slug'] ?? null;

        foreach ($levels as $option) {
            $chosen = is_string($slug) ? $option['course_slug'] === $slug : in_array($learner->level, $option['levels'], true);
            if ($chosen) {
                return ['slug' => $option['course_slug'], 'title' => $option['title'], 'level_label' => $option['level_label'], 'stages_count' => $option['stages_count']];
            }
        }

        return null;
    }

    /**
     * A step of the Mini App's own columns (`name`, `age`, `goals`): the column and the progress in one UPDATE under the row lock.
     *
     * @param  array<string, mixed>  $values
     */
    private function saveOwn(Learner $learner, string $step, array $values): void
    {
        $changed = false;
        $marks = ['started' => false, 'first' => false];

        DB::transaction(function () use ($learner, $step, $values, &$changed, &$marks): void {
            $lock = Learner::query()->whereKey($learner->id);
            /** @var Learner $fresh */
            $fresh = (DB::connection()->getDriverName() === 'pgsql' ? $lock->lock('for no key update') : $lock->lockForUpdate())->firstOrFail();
            if ($fresh->onboarding_state === OnboardingService::DONE) {
                throw TwaOnboardingException::completed();
            }

            foreach ($values as $field => $value) {
                $changed = $changed || $fresh->getAttribute($field) !== $value;
            }
            $fresh->fill($values);
            $marks = $this->advance($fresh, $step);
            $fresh->save();

            // The caller answers from the passed model: brought up to date without reading the row again.
            $learner->setRawAttributes($fresh->getAttributes(), true);
        });

        $this->recordStep($learner, $step, $marks, $changed);
    }

    /**
     * A step written by {@see LearnerSettingsService::update()}: the state check and the progress run under its lock, in its UPDATE.
     *
     * @param  array<string, mixed>  $changes
     * @param  array<string, string>  $props  added to `onboarding.step_completed`
     */
    private function saveSetting(Learner $learner, string $step, array $changes, ?string $courseSlug = null, array $props = []): void
    {
        $changed = false;
        $marks = ['started' => false, 'first' => false];

        $this->settings->update($learner, $changes, underLock: function (Learner $fresh) use ($step, $changes, $courseSlug, &$changed, &$marks): void {
            if ($fresh->onboarding_state === OnboardingService::DONE) {
                throw TwaOnboardingException::completed();
            }

            foreach ($changes as $field => $value) {
                $current = $field === 'reminder_time' ? $this->settings->shortTime($fresh->reminder_time) : $fresh->getAttribute($field);
                $changed = $changed || $current !== $value;
            }
            $previousSlug = OnboardingChoices::progress($fresh)['course_slug'] ?? null;
            $changed = $changed || ($courseSlug !== null && $previousSlug !== $courseSlug);
            $marks = $this->advance($fresh, $step, $courseSlug);
        });

        $this->recordStep($learner, $step, $marks, $changed, $props);
    }

    /** @param  array<string, mixed>  $input */
    private function saveLevel(Learner $learner, array $input): void
    {
        $level = $this->oneOf($input['level'] ?? null, OnboardingService::LEVELS, 'level');
        $slug = $input['course_slug'] ?? null;

        // The course must be the one the level screen offers for this level (the cached catalog, no statement).
        $offered = null;
        foreach ($this->levelOptions($this->locales->default()) as $option) {
            if (in_array($level, $option['levels'], true)) {
                $offered = $option['course_slug'];
            }
        }
        if (! is_string($slug) || $offered !== $slug) {
            throw InvalidSettingException::invalid('course_slug');
        }

        $this->saveSetting($learner, 'level', ['level' => $level], $slug);
    }

    /** @param  array<string, mixed>  $input */
    private function saveReminder(Learner $learner, array $input): void
    {
        $enabled = $input['enabled'] ?? null;
        $time = $input['time'] ?? null;
        if (! is_bool($enabled) || ($enabled && ! is_string($time))) {
            throw InvalidSettingException::invalid($enabled === null || ! is_bool($enabled) ? 'enabled' : 'time');
        }
        $writeAccess = $this->oneOf($input['write_access'] ?? null, OnboardingChoices::WRITE_ACCESS, 'write_access');

        // The time itself (a quarter of an hour) is LearnerSettingsService's rule; the answer to "messages from the bot" only goes to the event.
        $this->saveSetting($learner, 'reminder', ['reminder_time' => $enabled ? $time : null], props: ['write_access' => $writeAccess]);
    }

    /**
     * Moves the progress of the locked row on (not saved): the next screen, the first step, the steps answered, the chosen course.
     *
     * @return array{started: bool, first: bool} whether this is the learner's first step at all (the bot never started them) / of this step
     */
    private function advance(Learner $fresh, string $step, ?string $courseSlug = null): array
    {
        $progress = OnboardingChoices::progress($fresh);
        $started = $fresh->onboarding_state === 'new' && ! isset($progress['started_at']);
        $answered = array_values(array_filter(is_array($progress['answered'] ?? null) ? $progress['answered'] : [], 'is_string'));
        $first = ! in_array($step, $answered, true);

        $progress['step'] = OnboardingChoices::next($step);
        $progress['started_at'] ??= CarbonImmutable::now()->utc()->toIso8601String();
        $progress['answered'] = $first ? [...$answered, $step] : $answered;
        if ($courseSlug !== null) {
            $progress['course_slug'] = $courseSlug;
        }

        $fresh->meta = [...($fresh->meta ?? []), OnboardingChoices::META_KEY => $progress];

        return ['started' => $started, 'first' => $first];
    }

    /**
     * @param  array{started: bool, first: bool}  $marks
     * @param  array<string, string>  $props
     */
    private function recordStep(Learner $learner, string $step, array $marks, bool $changed, array $props = []): void
    {
        if ($marks['started']) {
            $this->events->record(ProductEventName::OnboardingStarted, [
                'channel' => OnboardingChoices::CHANNEL,
                'source_kind' => $learner->acquisition_source === null ? 'direct' : explode(':', $learner->acquisition_source, 2)[0],
            ], learner: $learner);
        }

        if ($marks['first'] || $changed) {
            $this->events->record(ProductEventName::OnboardingStepCompleted, [
                'step' => $step,
                'channel' => OnboardingChoices::CHANNEL,
                ...$props,
            ], learner: $learner);
        }
    }

    /** The name by the rules of the names of the cup (docs/33 §2.3): clean, or refused with the reason. */
    private function displayName(mixed $name): string
    {
        if ($name !== null && ! is_string($name)) {
            throw InvalidSettingException::invalid('display_name');
        }

        try {
            return DisplayName::validate($name);
        } catch (CupException $e) {
            $reason = $e->details['reason'] ?? DisplayName::REASON_EMPTY;

            throw TwaOnboardingException::displayNameInvalid(is_string($reason) ? $reason : DisplayName::REASON_EMPTY);
        }
    }

    /**
     * 1-6 of the goals, no repeats, in the order given.
     *
     * @return list<string>
     */
    private function goalList(mixed $goals): array
    {
        if (! is_array($goals) || ! array_is_list($goals) || $goals === [] || count($goals) > count(OnboardingChoices::GOALS)
            || count(array_unique($goals, SORT_REGULAR)) !== count($goals)) {
            throw InvalidSettingException::invalid('goals');
        }

        return array_map(fn (mixed $goal): string => $this->oneOf($goal, OnboardingChoices::GOALS, 'goals'), $goals);
    }

    /** @param  list<string>  $allowed */
    private function oneOf(mixed $value, array $allowed, string $field): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : throw InvalidSettingException::invalid($field);
    }
}
