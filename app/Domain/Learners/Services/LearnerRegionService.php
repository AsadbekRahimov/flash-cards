<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Domain\Learners\Exceptions\InvalidSettingException;
use App\Domain\Learners\Exceptions\RegionChangeTooSoonException;
use App\Domain\Learners\Exceptions\RegionRevertUnavailableException;
use App\Domain\Learners\Support\Regions;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The region of a learner (docs/35 §2.3, R2): `learners.region_code` - a code of {@see Regions} or NULL ("not given") - and
 * `learners.region_changed_at`, the moment of the last accepted choice of a region.
 *
 * Rules of {@see applyLocked()} (the one place that writes the region; callers hold the learner's row lock):
 *  - the same value again changes nothing;
 *  - the first choice (`region_changed_at` is empty) is accepted at once;
 *  - any other choice of a region is accepted once in {@see changeDays()} days from the last one, else
 *    {@see RegionChangeTooSoonException} (422 `region_change_too_soon` with the date);
 *  - a reset to NULL is always accepted and does NOT move `region_changed_at`: the next choice after a reset waits for the same 30 days
 *    (otherwise a reset would get around the rule);
 *  - every accepted change keeps what it replaced (`meta.region_change_undo`): within {@see UNDO_MINUTES} minutes {@see revert()} puts
 *    back the region and `region_changed_at` exactly as they were, like the undo of a time zone change (a tap by mistake costs no
 *    30 days). A newer change overwrites the snapshot; the undo consumes it.
 *
 * After the commit: the product event `region.set {from, to, via}` (`via`: onboarding / settings / prompt / undo) and the hook of the
 * region tables ({@see recordChange()}: an empty region of the open month is filled, "all time" is recomputed - docs/35 §2.4, R6).
 *
 * "Not now" on the card of the Rating tab hides the card for `regions.prompt_snooze_days` days
 * (`meta.region_prompt_dismissed_at`, {@see dismissPrompt()}, {@see promptVisible()}).
 */
final class LearnerRegionService
{
    public const VIA_ONBOARDING = 'onboarding';

    public const VIA_SETTINGS = 'settings';

    public const VIA_PROMPT = 'prompt';

    public const VIA_UNDO = 'undo';

    /** Where a learner can choose the region from (the `region_via` of `PATCH /me/settings`). */
    public const CLIENT_VIAS = [self::VIA_SETTINGS, self::VIA_PROMPT];

    /** How long after a change of the region it can be taken back. */
    public const UNDO_MINUTES = 10;

    /** `learners.meta` key: `{code, changed_at, previous_changed_at}` - what {@see revert()} puts back. */
    public const UNDO_META = 'region_change_undo';

    /** `learners.meta` key: UTC instant of the last "Not now" on the region card. */
    public const PROMPT_DISMISSED_META = 'region_prompt_dismissed_at';

    public function __construct(
        private readonly ProductEventRecorder $events,
        private readonly MonthlyRatingService $rating,
    ) {}

    /** The minimum number of days between two choices of a region (`regions.change_days`, 30). */
    public static function changeDays(): int
    {
        return max(1, (int) config('regions.change_days', 30));
    }

    /**
     * Sets the region in its own transaction under the learner's row lock and records `region.set`.
     *
     * @throws InvalidSettingException for a code that is not a region
     * @throws RegionChangeTooSoonException for a change before the 30 days have passed
     */
    public function set(Learner $learner, ?string $code, string $via, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        self::assertValid($code);

        $change = DB::transaction(function () use ($learner, $code, $now): ?array {
            $fresh = self::lock($learner);
            $change = $this->applyLocked($fresh, $code, $now);
            if ($change !== null) {
                $fresh->save();
            }

            return $change;
        });

        $learner->refresh();
        if ($change !== null) {
            $this->recordChange($learner, $change, $via);
        }
    }

    /**
     * Applies a choice to the LOCKED row in memory (the caller saves it, inside its transaction, and records the event after the
     * commit with {@see recordChange()}). Returns what changed, or null when nothing did.
     *
     * @return array{from: string|null, to: string|null}|null
     *
     * @throws InvalidSettingException for a code that is not a region
     * @throws RegionChangeTooSoonException for a change before the 30 days have passed
     */
    public function applyLocked(Learner $fresh, ?string $code, CarbonImmutable $now): ?array
    {
        self::assertValid($code);

        $from = $fresh->region_code;
        if ($from === $code) {
            return null;
        }

        if ($code !== null) {
            $availableAt = $this->changeAvailableAt($fresh, $now);
            if ($availableAt !== null) {
                throw new RegionChangeTooSoonException($availableAt);
            }
        }

        $fresh->meta = [
            ...($fresh->meta ?? []),
            self::UNDO_META => [
                'code' => $from,
                'changed_at' => $now->utc()->toIso8601String(),
                'previous_changed_at' => $fresh->region_changed_at?->toImmutable()->utc()->toIso8601String(),
            ],
        ];
        $fresh->region_code = $code;
        if ($code !== null) {
            $fresh->region_changed_at = Carbon::instance($now->utc());
        }

        return ['from' => $from, 'to' => $code];
    }

    /**
     * After the commit of an accepted change (every path calls it: set, the settings, the onboarding, the undo): the event, and the hook
     * of the region tables (docs/35 §2.4, R6 - an empty region of the open month takes the new one, "all time" follows the current
     * region; MonthlyRatingService::regionChanged, reported, never thrown).
     *
     * @param  array{from: string|null, to: string|null}  $change
     */
    public function recordChange(Learner $learner, array $change, string $via): void
    {
        $this->events->record(ProductEventName::RegionSet, [...$change, 'via' => $via], learner: $learner);
        $this->rating->regionChanged($learner);
    }

    /**
     * When the learner may choose another region: null = right now (the first choice, or the 30 days have passed). A reset to
     * "not given" is never limited; the screen says the date before the learner tries.
     */
    public function changeAvailableAt(Learner $learner, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        if ($learner->region_changed_at === null) {
            return null;
        }

        $availableAt = $learner->region_changed_at->toImmutable()->utc()->addDays(self::changeDays());

        return ($now ?? CarbonImmutable::now())->isBefore($availableAt) ? $availableAt : null;
    }

    /**
     * Takes back the last change of the region if it is at most {@see UNDO_MINUTES} old: the region and `region_changed_at` come back
     * exactly as they were. The snapshot is consumed, a second undo is refused.
     *
     * @throws RegionRevertUnavailableException no change to take back, or the 10 minutes have passed
     */
    public function revert(Learner $learner, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        $change = DB::transaction(function () use ($learner, $now): array {
            $fresh = self::lock($learner);

            $undo = self::undoSnapshot($fresh);
            if ($undo === null || ! $now->isBefore($undo['changed_at']->addMinutes(self::UNDO_MINUTES))) {
                throw new RegionRevertUnavailableException;
            }

            $meta = $fresh->meta ?? [];
            unset($meta[self::UNDO_META]);

            $change = ['from' => $fresh->region_code, 'to' => $undo['code']];
            $fresh->region_code = $undo['code'];
            $fresh->region_changed_at = $undo['previous_changed_at'] === null ? null : Carbon::instance($undo['previous_changed_at']);
            $fresh->meta = $meta;
            $fresh->save();

            return $change;
        });

        $learner->refresh();
        $this->recordChange($learner, $change, self::VIA_UNDO);
    }

    /** Until when the last change of the region can be taken back; null = it cannot (no change, or too late). */
    public function undoAvailableUntil(Learner $learner, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $undo = self::undoSnapshot($learner);
        if ($undo === null) {
            return null;
        }

        $until = $undo['changed_at']->addMinutes(self::UNDO_MINUTES);

        return ($now ?? CarbonImmutable::now())->isBefore($until) ? $until : null;
    }

    /** Whether the card "Choose your region" is shown: no region, and no "Not now" in the last 30 days. */
    public function promptVisible(Learner $learner, ?CarbonImmutable $now = null): bool
    {
        if ($learner->region_code !== null) {
            return false;
        }

        $raw = ($learner->meta ?? [])[self::PROMPT_DISMISSED_META] ?? null;
        if (! is_string($raw)) {
            return true;
        }

        try {
            $until = CarbonImmutable::parse($raw)->utc()->addDays(max(1, (int) config('regions.prompt_snooze_days', 30)));
        } catch (Throwable) {
            return true;
        }

        return ! ($now ?? CarbonImmutable::now())->isBefore($until);
    }

    /** "Not now" on the region card: hidden for the next 30 days. Idempotent; a learner with a region has no card to hide. */
    public function dismissPrompt(Learner $learner, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        DB::transaction(function () use ($learner, $now): void {
            $fresh = self::lock($learner);
            $fresh->meta = [...($fresh->meta ?? []), self::PROMPT_DISMISSED_META => $now->utc()->toIso8601String()];
            $fresh->save();
        });

        $learner->refresh();
    }

    /** @throws InvalidSettingException */
    private static function assertValid(?string $code): void
    {
        if ($code !== null && ! Regions::isValid($code)) {
            throw InvalidSettingException::invalid('region_code');
        }
    }

    /**
     * The well-formed undo snapshot of `learners.meta`, or null.
     *
     * @return array{code: string|null, changed_at: CarbonImmutable, previous_changed_at: CarbonImmutable|null}|null
     */
    private static function undoSnapshot(Learner $learner): ?array
    {
        $raw = ($learner->meta ?? [])[self::UNDO_META] ?? null;
        if (! is_array($raw) || ! is_string($raw['changed_at'] ?? null)) {
            return null;
        }

        $code = $raw['code'] ?? null;
        $previous = $raw['previous_changed_at'] ?? null;
        if (($code !== null && ! Regions::isValid($code)) || ($previous !== null && ! is_string($previous))) {
            return null;
        }

        try {
            return [
                'code' => $code,
                'changed_at' => CarbonImmutable::parse($raw['changed_at'])->utc(),
                'previous_changed_at' => $previous === null ? null : CarbonImmutable::parse($previous)->utc(),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** The learner's row under `FOR NO KEY UPDATE` on PostgreSQL (as the settings and the card picker take it). */
    private static function lock(Learner $learner): Learner
    {
        $lock = Learner::query()->whereKey($learner->id);

        /** @var Learner */
        return (DB::connection()->getDriverName() === 'pgsql' ? $lock->lock('for no key update') : $lock->lockForUpdate())->firstOrFail();
    }
}
