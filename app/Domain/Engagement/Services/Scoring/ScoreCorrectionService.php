<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services\Scoring;

use App\Domain\Engagement\DTO\ScoreCorrection;
use App\Domain\Engagement\Enums\LeagueWeekStatus;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Engagement\Exceptions\ScoreCorrectionException;
use App\Domain\Engagement\Exceptions\ScoreCorrectionRetry;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Domain\Engagement\Support\ScoreCount;
use App\Domain\Learners\Support\StoredDate;
use App\Domain\Operations\Support\PureCache;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\LearnerScoreDay;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The correction of a learner's rating day by an administrator (docs/32 §6.4, S11): credits of the day are voided (or a void is taken
 * back) and the day is RECOUNTED from the credits left - the very rule of the answer ({@see ScoreCount::add()}), in the order of their
 * ids, with the categories and versions stored in the day row. Nothing is ever "set to N points": the contribution of a day is always
 * what its journal gives (60 → 45 is voiding credits worth 15).
 *
 * One transaction, locks in the order of the answer and of the close, so no cycle with either:
 *
 *   1. `league_groups` of the learner's membership in the week of the day, FOR SHARE - the close finalises a group under FOR UPDATE of
 *      it and reads the day rows there, so the correction either lands before the final (which then counts it) or finds the group
 *      closed; a closed group, a week not `open`, or a week that has ended = the WEEKLY count is final and is left as it is (a closed
 *      week never changes: its promotions are history). No membership: the weekly count is final once the week has ended.
 *   2. `learner_lexemes` of every lexeme credited that day, `ORDER BY lexeme_id`, FOR UPDATE - the answer locks one lexeme row and
 *      then the day; locking the lexemes before the day keeps that order (docs/32 §12.3);
 *   3. `learner_score_days` of the day, FOR UPDATE. The credits are read again under it: a credit of a lexeme not locked in step 2 (an
 *      answer committed in between) makes the transaction start over (at most {@see ATTEMPTS} times) - the lexemes are never locked
 *      after the day.
 *
 * Then: the chosen credits voided / restored (a credit already in that state is left alone - a repeat changes nothing, writes
 * nothing and subtracts nothing twice), the two counts replayed (the monthly always on a v2 month - "all time" and the record of the
 * month follow the journal; the weekly only while the week is not final), the stored points of the active credits brought to the
 * replay, `revision + 1`, the long-repetition marks of the affected lexemes recomputed (`first_long_on`, `long_<category>_on`;
 * `confirmed_on`, `last_attempt_on` and `last_credit_due_at` are NOT rolled back: a voided word does not earn twice - conservative),
 * an `audit_logs` row. After the commit: the learner's row of the open month of the day is recounted, their "all time" rows recomputed (whatever the
 * month) and the cached tables dropped. A
 * closed month keeps its final ranks and candidates (the prizes are the administrator's decision in the review of the month).
 */
final class ScoreCorrectionService
{
    public const ACTION_VOIDED = 'score.day_voided';

    public const ACTION_RESTORED = 'score.day_restored';

    public const MAX_REASON_LENGTH = 255;

    private const ATTEMPTS = 3;

    public function __construct(
        private readonly ScoreRules $rules,
        private readonly MonthlyRatingService $months,
        private readonly ScoreTotalsService $totals,
    ) {}

    /**
     * Voids the given credits of the learner's rating day (`$creditIds` null = every credit of the day) with a reason.
     *
     * @param  list<int>|null  $creditIds
     */
    public function void(Learner $learner, string $ratingDay, ?array $creditIds, string $reason, User $by, ?string $ip = null, ?CarbonImmutable $now = null): ScoreCorrection
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw ScoreCorrectionException::reasonInvalid(self::MAX_REASON_LENGTH);
        }

        return $this->correct($learner, $ratingDay, $creditIds, true, $reason, $by, $ip, $now ?? CarbonImmutable::now());
    }

    /**
     * Takes the void of the given credits back (`$creditIds` null = every voided credit of the day); the day is recounted the same way.
     *
     * @param  list<int>|null  $creditIds
     */
    public function restore(Learner $learner, string $ratingDay, ?array $creditIds, User $by, ?string $ip = null, ?CarbonImmutable $now = null): ScoreCorrection
    {
        return $this->correct($learner, $ratingDay, $creditIds, false, null, $by, $ip, $now ?? CarbonImmutable::now());
    }

    /** @param  list<int>|null  $creditIds */
    private function correct(Learner $learner, string $ratingDay, ?array $creditIds, bool $void, ?string $reason, User $by, ?string $ip, CarbonImmutable $now): ScoreCorrection
    {
        $day = CarbonImmutable::parse($ratingDay, $this->rules->timezone())->toDateString();

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            try {
                [$result, $context] = DB::transaction(fn (): array => $this->correctOnce($learner, $day, $creditIds, $void, $reason, $by, $ip, $now));
            } catch (ScoreCorrectionRetry) {
                continue;
            }

            if ($result->changed()) {
                $this->afterCommit($learner, $context, $result, $now);
            }

            return $result;
        }

        throw ScoreCorrectionException::busy();
    }

    /**
     * @param  list<int>|null  $creditIds
     * @return array{0: ScoreCorrection, 1: array{week_starts_on: string, month_starts_on: string, group_id: int|null, week_version: int}}
     */
    private function correctOnce(Learner $learner, string $day, ?array $creditIds, bool $void, ?string $reason, User $by, ?string $ip, CarbonImmutable $now): array
    {
        $learnerId = (int) $learner->id;

        /** @var LearnerScoreDay|null $unlocked */
        $unlocked = LearnerScoreDay::query()->where('learner_id', $learnerId)->where('rating_day', StoredDate::of($day))->first();
        if ($unlocked === null) {
            throw ScoreCorrectionException::dayNotFound($learnerId, $day);
        }

        // 1. The group of the week, FOR SHARE: the close of the group waits for us, or has happened and we leave the week alone.
        [$weekFrozen, $groupId, $weekVersion] = $this->weekState($learnerId, $unlocked->week_starts_on->toDateString(), $now);

        // 2. The lexemes of the day, in order, then 3. the day.
        $lexemes = $this->creditedLexemes($learnerId, $day);
        $locked = $this->lockLexemes($learnerId, $lexemes);

        /** @var LearnerScoreDay $row */
        $row = LearnerScoreDay::query()->where('learner_id', $learnerId)->where('rating_day', StoredDate::of($day))->lockForUpdate()->firstOrFail();

        $credits = $this->credits($learnerId, $day);
        foreach ($credits as $credit) {
            if (! isset($locked[$credit['lexeme_id']])) {
                throw new ScoreCorrectionRetry; // an answer credited a new lexeme in between: start over, the lexemes first
            }
        }

        // The credits to change: the chosen ones of THIS learner and day, not in the target state already.
        $byId = [];
        foreach ($credits as $credit) {
            $byId[$credit['id']] = $credit['voided'];
        }
        if ($creditIds !== null) {
            $unknown = array_values(array_diff($creditIds, array_keys($byId)));
            if ($unknown !== []) {
                throw ScoreCorrectionException::creditNotFound(array_map('intval', $unknown));
            }
        }
        $targets = [];
        foreach ($creditIds ?? array_keys($byId) as $id) {
            if ($byId[$id] !== $void) {
                $targets[] = (int) $id;
            }
        }
        sort($targets);

        $weekBefore = (int) $row->week_points;
        $monthBefore = (int) $row->month_points;
        if ($targets === []) {
            return [new ScoreCorrection($day, [], $weekBefore, $weekBefore, $monthBefore, $monthBefore, (int) $row->revision, $weekFrozen), $this->context($row, $groupId, $weekVersion)];
        }

        $stamp = (string) (new LearnerScoreDay)->fromDateTime($now->setTimezone('UTC'));
        DB::table('score_credits')
            ->whereIn('id', $targets)
            ->update($void
                ? ['voided_at' => $stamp, 'voided_by_user_id' => $by->id, 'void_reason' => $reason]
                : ['voided_at' => null, 'voided_by_user_id' => null, 'void_reason' => null]);

        // The replay of the day: the credits active after this change, oldest first (the read is ordered by id).
        $active = array_values(array_filter(
            $credits,
            static fn (array $c): bool => in_array($c['id'], $targets, true) ? ! $void : ! $c['voided'],
        ));
        [$dayChanges, $points] = $this->replay($row, $active, $weekFrozen);
        $dayChanges['revision'] = (int) $row->revision + 1;
        $dayChanges['updated_at'] = $stamp;

        LearnerScoreDay::query()->where('learner_id', $learnerId)->where('rating_day', StoredDate::of($day))->update($dayChanges);

        foreach ($active as $credit) {
            [$week, $month] = $points[$credit['id']];
            if ($week !== $credit['week_points'] || $month !== $credit['month_points']) {
                DB::table('score_credits')->where('id', $credit['id'])->update(['week_points' => $week, 'month_points' => $month]);
            }
        }

        $this->recomputeLongMarks($learnerId, $day, self::category($row->month_category), $locked, $active, $points);

        $result = new ScoreCorrection(
            ratingDay: $day,
            changedCreditIds: $targets,
            weekPointsBefore: $weekBefore,
            weekPointsAfter: (int) ($dayChanges['week_points'] ?? $weekBefore),
            monthPointsBefore: $monthBefore,
            monthPointsAfter: (int) ($dayChanges['month_points'] ?? $monthBefore),
            revision: $dayChanges['revision'],
            weekFrozen: $weekFrozen,
        );

        AuditLog::query()->create([
            'user_id' => $by->id,
            'action' => $void ? self::ACTION_VOIDED : self::ACTION_RESTORED,
            'entity_type' => Learner::class,
            'entity_id' => $learnerId,
            'ip' => $ip,
            'meta' => [...$result->toArray(), ...($reason === null ? [] : ['reason' => $reason])],
        ]);

        return [$result, $this->context($row, $groupId, $weekVersion)];
    }

    /**
     * Whether the weekly count of a day of the week starting `$weekStartsOn` is final, with the learner's group (locked FOR SHARE when
     * there is one) and the week's rules version.
     *
     * @return array{0: bool, 1: int|null, 2: int}
     */
    private function weekState(int $learnerId, string $weekStartsOn, CarbonImmutable $now): array
    {
        $ended = $now->setTimezone($this->rules->timezone())->toDateString() > CarbonImmutable::parse($weekStartsOn, $this->rules->timezone())->addDays(6)->toDateString();

        $membership = DB::table('league_members as m')
            ->join('league_weeks as w', 'w.id', '=', 'm.league_week_id')
            ->where('m.learner_id', $learnerId)
            ->where('w.starts_on', StoredDate::of($weekStartsOn))
            ->first(['m.league_group_id', 'w.status', 'w.rules_version']);
        if ($membership === null) {
            return [$ended, null, $this->rules->versionFor($weekStartsOn)];
        }
        $membership = get_object_vars($membership);

        // Read under the lock: a group the close finalised meanwhile is seen closed. (A week turned `closing` after the read above is
        // harmless: the finalise of this group waits for our share lock and then reads the corrected day.)
        $group = DB::table('league_groups')->where('id', (int) $membership['league_group_id'])->sharedLock()->first(['id', 'closed_at']);
        $closed = $group === null || get_object_vars($group)['closed_at'] !== null;

        return [$ended || $closed || $membership['status'] !== LeagueWeekStatus::Open->value, (int) $membership['league_group_id'], (int) $membership['rules_version']];
    }

    /** @return list<int> the distinct lexemes of the day's credits (voided or not), ascending */
    private function creditedLexemes(int $learnerId, string $day): array
    {
        $lexemes = DB::table('score_credits')
            ->where('learner_id', $learnerId)
            ->where('rating_day', StoredDate::of($day))
            ->orderBy('lexeme_id')
            ->distinct()
            ->pluck('lexeme_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return array_values($lexemes);
    }

    /**
     * `learner_lexemes` of the lexemes, `ORDER BY lexeme_id FOR UPDATE` (the order every corrector takes them in).
     *
     * @param  list<int>  $lexemes
     * @return array<int, array{first_long_on: string|null, long_free_on: string|null, long_pro_on: string|null}> lexeme id => marks
     */
    private function lockLexemes(int $learnerId, array $lexemes): array
    {
        if ($lexemes === []) {
            return [];
        }

        $rows = DB::table('learner_lexemes')
            ->where('learner_id', $learnerId)
            ->whereIn('lexeme_id', $lexemes)
            ->orderBy('lexeme_id')
            ->lockForUpdate()
            ->get(['lexeme_id', 'first_long_on', 'long_free_on', 'long_pro_on']);

        $out = [];
        foreach ($rows as $row) {
            $data = get_object_vars($row);
            $out[(int) $data['lexeme_id']] = [
                'first_long_on' => self::date($data['first_long_on']),
                'long_free_on' => self::date($data['long_free_on']),
                'long_pro_on' => self::date($data['long_pro_on']),
            ];
        }

        return $out;
    }

    /**
     * Every credit of the day (the unique `(learner_id, rating_day, lexeme_id)`), by id.
     *
     * @return list<array{id: int, lexeme_id: int, kind: ScoreKind, week_points: int, month_points: int, voided: bool, at: string}>
     */
    private function credits(int $learnerId, string $day): array
    {
        $rows = DB::table('score_credits')
            ->where('learner_id', $learnerId)
            ->where('rating_day', StoredDate::of($day))
            ->orderBy('id')
            ->get(['id', 'lexeme_id', 'kind', 'week_points', 'month_points', 'voided_at', 'created_at']);

        $out = [];
        foreach ($rows as $row) {
            $data = get_object_vars($row);
            $out[] = [
                'id' => (int) $data['id'],
                'lexeme_id' => (int) $data['lexeme_id'],
                'kind' => ScoreKind::from((string) $data['kind']),
                'week_points' => (int) $data['week_points'],
                'month_points' => (int) $data['month_points'],
                'voided' => $data['voided_at'] !== null,
                'at' => (string) $data['created_at'],
            ];
        }

        return $out;
    }

    /**
     * The day recounted from its active credits: the raw facts always; each count on v2 replayed through {@see ScoreCount} unless it is
     * final (the week of a closed group / ended week keeps its stored count and the stored weekly points of its credits).
     *
     * @param  list<array{id: int, lexeme_id: int, kind: ScoreKind, week_points: int, month_points: int, voided: bool, at: string}>  $active
     * @return array{0: array<string, int|string|null>, 1: array<int, array{0: int, 1: int}>} the day's new columns, credit id => [week, month] points
     */
    private function replay(LearnerScoreDay $row, array $active, bool $weekFrozen): array
    {
        $weekVersion = (int) $row->week_version;
        $monthVersion = (int) $row->month_version;
        $reference = $this->rules->reference($weekVersion, $monthVersion)
            ?? throw new RuntimeException('A rating day is corrected without a configured rules version.');

        $changes = ['raw_points' => 0, 'new_count' => 0, 'review_count' => 0, 'long_count' => 0];
        foreach ($active as $credit) {
            $changes['raw_points'] += $reference->pointsFor($credit['kind']);
            $changes['new_count'] += $credit['kind'] === ScoreKind::New ? 1 : 0;
            $changes['review_count'] += $credit['kind'] === ScoreKind::New ? 0 : 1;
            $changes['long_count'] += $credit['kind'] === ScoreKind::Long ? 1 : 0;
        }

        /** @var array<string, list<int>> $added count => the replayed points of each active credit (stored ones when not replayed) */
        $added = [
            'week' => array_map(static fn (array $c): int => $c['week_points'], $active),
            'month' => array_map(static fn (array $c): int => $c['month_points'], $active),
        ];

        $replayable = array_map(static fn (array $c): array => ['kind' => $c['kind'], 'at' => $c['at']], $active);
        foreach (['week', 'month'] as $prefix) {
            $version = $prefix === 'week' ? $weekVersion : $monthVersion;
            $rules = $version >= 2 ? $this->rules->version($version) : null;
            if ($rules === null || ($prefix === 'week' && $weekFrozen)) {
                continue;
            }

            [$added[$prefix], $count] = ScoreCount::replay($replayable, $rules, self::category($row->getAttribute("{$prefix}_category")));
            $changes["{$prefix}_points"] = $count->points;
            $changes["{$prefix}_new"] = $count->new;
            $changes["{$prefix}_review"] = $count->review;
            $changes["{$prefix}_long"] = $count->long;
            $changes["{$prefix}_capped_at"] = $count->cappedAt;
        }

        $points = [];
        foreach ($active as $i => $credit) {
            $points[$credit['id']] = [$added['week'][$i], $added['month'][$i]];
        }

        return [$changes, $points];
    }

    /**
     * The long-repetition marks of the day's lexemes after the replay. For a mark `m` of a lexeme and the day `D`:
     *   - the lexeme qualifies on D (an active long credit that added points - in the month's count for `long_<category>_on`, in either
     *     count for `first_long_on`): m = D when m was empty or later than D;
     *   - it no longer qualifies and m = D: m = the next qualifying day after D from the journal (kept for every day of an open month),
     *     or empty;
     *   - otherwise m stays (an earlier day set it; a later day is not this correction's business).
     *
     * @param  array<int, array{first_long_on: string|null, long_free_on: string|null, long_pro_on: string|null}>  $locked
     * @param  list<array{id: int, lexeme_id: int, kind: ScoreKind, week_points: int, month_points: int, voided: bool, at: string}>  $active
     * @param  array<int, array{0: int, 1: int}>  $points
     */
    private function recomputeLongMarks(int $learnerId, string $day, ScoreCategory $monthCategory, array $locked, array $active, array $points): void
    {
        $qualifies = [];
        foreach ($active as $credit) {
            if ($credit['kind'] !== ScoreKind::Long) {
                continue;
            }
            [$week, $month] = $points[$credit['id']];
            $qualifies[$credit['lexeme_id']] = ['any' => $week > 0 || $month > 0, 'month' => $month > 0];
        }

        $categoryColumn = $monthCategory === ScoreCategory::Pro ? 'long_pro_on' : 'long_free_on';
        foreach ($locked as $lexemeId => $marks) {
            $changes = [];
            foreach (['first_long_on' => 'any', $categoryColumn => 'month'] as $column => $test) {
                $current = $marks[$column];
                $now = $qualifies[$lexemeId][$test] ?? false;
                $next = match (true) {
                    $now => $current === null || $current > $day ? $day : $current,
                    $current === $day => $this->nextLongDay($learnerId, $lexemeId, $day, $column === 'first_long_on' ? null : $monthCategory),
                    default => $current,
                };
                if ($next !== $current) {
                    $changes[$column] = $next === null ? null : StoredDate::of($next);
                }
            }

            if ($changes !== []) {
                DB::table('learner_lexemes')->where('learner_id', $learnerId)->where('lexeme_id', $lexemeId)->update($changes);
            }
        }
    }

    /** The first rating day after `$day` with an active long credit of the lexeme that added points (to the month of `$category`, if given). */
    private function nextLongDay(int $learnerId, int $lexemeId, string $day, ?ScoreCategory $category): ?string
    {
        $query = DB::table('score_credits as c')
            ->where('c.learner_id', $learnerId)
            ->where('c.lexeme_id', $lexemeId)
            ->where('c.rating_day', '>', StoredDate::of($day))
            ->where('c.kind', ScoreKind::Long->value)
            ->whereNull('c.voided_at');

        if ($category === null) {
            $query->where(static fn ($q) => $q->where('c.week_points', '>', 0)->orWhere('c.month_points', '>', 0));
        } else {
            $query->where('c.month_points', '>', 0)
                ->join('learner_score_days as d', static fn ($j) => $j->on('d.learner_id', '=', 'c.learner_id')->on('d.rating_day', '=', 'c.rating_day'))
                ->where('d.month_category', $category->value);
        }

        return self::date($query->min('c.rating_day'));
    }

    /**
     * @return array{week_starts_on: string, month_starts_on: string, group_id: int|null, week_version: int}
     */
    private function context(LearnerScoreDay $row, ?int $groupId, int $weekVersion): array
    {
        return [
            'week_starts_on' => $row->week_starts_on->toDateString(),
            'month_starts_on' => $row->month_starts_on->toDateString(),
            'group_id' => $groupId,
            'week_version' => $weekVersion,
        ];
    }

    /**
     * After the commit: the learner's row of the month of the day, when that month is still open, is recounted from the corrected day
     * (and the cached tops dropped); the cached standings of the learner's group of an open week are dropped. Reported, never thrown:
     * the correction is committed.
     *
     * @param  array{week_starts_on: string, month_starts_on: string, group_id: int|null, week_version: int}  $context
     */
    private function afterCommit(Learner $learner, array $context, ScoreCorrection $result, CarbonImmutable $now): void
    {
        DB::afterCommit(function () use ($learner, $context, $result, $now): void {
            try {
                if (! $result->weekFrozen && $context['group_id'] !== null) {
                    PureCache::forgetMany([LeagueService::cacheKey($context['group_id'], $context['week_version'])]);
                }

                $month = $this->months->findMonthStartingOn($context['month_starts_on']);
                if ($month !== null && $month->isOpen() && $this->months->refreshLearner($month, $learner, $now)) {
                    PureCache::forgetMany(MonthlyRatingService::topKeys($month->id));
                }
            } catch (Throwable $e) {
                report($e);
            }

            // "All time" follows the journal whatever the month (docs/32 §6.4: a closed month keeps its ranks, all time does not).
            try {
                $this->totals->recomputeLearner($learner->id, $now);
                $this->totals->forgetTables();
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    private static function category(mixed $value): ScoreCategory
    {
        return $value instanceof ScoreCategory ? $value : ScoreCategory::from((string) $value);
    }

    private static function date(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }
}
