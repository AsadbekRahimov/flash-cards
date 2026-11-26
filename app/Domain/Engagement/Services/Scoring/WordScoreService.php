<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services\Scoring;

use App\Domain\Engagement\DTO\ScoreRuleVersion;
use App\Domain\Engagement\Enums\LexemeConfirmSource;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Engagement\Enums\ScorePeriod;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Domain\Engagement\Enums\ScoringMode;
use App\Domain\Engagement\Support\ScoreCount;
use App\Domain\Learners\Support\StoredDate;
use App\Domain\Learning\Contracts\AnswerScoreRecorder;
use App\Domain\Learning\DTO\CardStateBefore;
use App\Domain\Learning\DTO\ScoredAnswer;
use App\Domain\Learning\DTO\ScoreDayCount;
use App\Domain\Learning\DTO\WordScoreOutcome;
use App\Domain\Learning\Enums\AnswerVerdict;
use App\Domain\Learning\Enums\WordScoreReason;
use App\Models\LearnerLexeme;
use App\Models\LearnerScoreDay;
use App\Models\ScoreCredit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The word points of one answer (docs/28, docs/32 §3; task S4): the one point where the training and the game credit a word.
 *
 * The decision, inside the transaction of the answer (docs/32 §3.4):
 *   1. off / no lexeme / the first meeting of a new card → nothing, not a statement;
 *   2. `learner_lexemes` of (learner, lexeme) FOR UPDATE - the attempt of the rating day (league zone, the moment the server accepts the
 *      answer). Spent already → `already_today`, nothing written. Else the attempt is spent now: a failed / assisted / revealed / unchecked
 *      answer only marks it (`closed`);
 *   3. a passed answer is classified (§3.3): `new` while the lexeme was never confirmed (once in the learner's history); else a
 *      repetition when the card was `review`, its scheduled due (`last_reviewed_at + interval_days`, not `next_review_at`) has come
 *      (60 s of slack), the due was not paid for yet (`last_credit_due_at`) and the card was not studied earlier the same rating day -
 *      `long` when the interval before was at least `long_interval_days`. Otherwise `early` / `same_cycle` / `not_due`: the attempt is
 *      spent, nothing is credited;
 *   4. a credit: the day row `learner_score_days` FOR UPDATE (the first credit of the day fixes the week and the month first -
 *      {@see ScorePeriodService::fixDay()} - and creates the row with their categories and versions), the journal row
 *      `score_credits` (ON CONFLICT DO NOTHING on `(learner_id, rating_day, lexeme_id)`: the database's guarantee behind the lock), the
 *      two counts updated from the locked values - the slot of the kind is spent while the category's limit is not reached, the points
 *      are what is left under the day cap, a count still on version 1 gets nothing - then the lexeme row once. The rule of one count is
 *      the pure {@see ScoreCount::add()}, shared with the correction of a day (ScoreCorrectionService);
 *   5. nothing of the league inside the answer's transaction: the outcome says `firstCreditOfDay`, and WordScoreHooks seats the
 *      learner after the commit (S8).
 *
 * Locks, always in this order: `learner_lexemes` → `learner_score_periods` → `learner_score_days`; the callers hold their own rows
 * before (`training_sessions → word_repetitions`, `game_runs`) and touch `learner_daily_stats` only after. ONE lexeme per answer, so two
 * answers of one learner never hold two lexeme rows against each other; the day row serialises the answers of one learner, so the
 * slots and the cap can not be overrun by a race (READ COMMITTED: the second `FOR UPDATE` reads the first's committed counters).
 *
 * Statements (the budget, pinned by WordScoreQueryBudgetTest):
 *   off, no lexeme, first meeting                       0
 *   attempt spent already                               1  (lexeme FOR UPDATE)
 *   closed / early / same_cycle / not_due               2  (+ UPDATE lexeme)        - the very first attempt of a lexeme: SELECT + INSERT
 *   credit on a day that has its row                    5  (lexeme, day FOR UPDATE, INSERT credit, UPDATE day, UPDATE lexeme)
 *                                                          - a lexeme met for the first time: SELECT + INSERT instead of the UPDATE
 *   the first credit of the rating day                 +2  (the periods read, INSERT day) and +2 more for the first of a week / month
 *   (+1 in the game for a repetition: the card is read there, without a lock)
 * The rows are written with the query builder: `learner_lexemes` and `learner_score_days` change no indexed column on the hot path,
 * so their UPDATEs stay HOT (fillfactor 90 / 70).
 */
final class WordScoreService implements AnswerScoreRecorder
{
    /** Slack on the scheduled due: a card asked a few seconds before its due (rounding, the clock of the client's queue) is on time. */
    public const DUE_SLACK_SECONDS = 60;

    public function __construct(
        private readonly ScoreRules $rules,
        private readonly ScorePeriodService $periods,
    ) {}

    public function records(): bool
    {
        return $this->rules->records() && $this->rules->versions() !== [];
    }

    public function recordsGame(): bool
    {
        return $this->records() && $this->rules->gameEnabled();
    }

    public function record(ScoredAnswer $answer): WordScoreOutcome
    {
        if (! $this->records() || ($answer->source === ScoreSource::Game && ! $this->rules->gameEnabled())) {
            return WordScoreOutcome::skipped(WordScoreReason::Off);
        }
        if ($answer->lexemeId === null) {
            return WordScoreOutcome::skipped(WordScoreReason::NoCatalog, $this->visibleOn($answer->now));
        }
        if ($answer->verdict === AnswerVerdict::Introductory) {
            return WordScoreOutcome::skipped(WordScoreReason::Introductory, $this->visibleOn($answer->now));
        }
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The word points are recorded inside the transaction of the answer.');
        }

        $now = $answer->now->setTimezone('UTC');
        $day = $this->rules->ratingDay($now)->toDateString();

        $lexeme = $this->lockLexeme($answer->learnerId, $answer->lexemeId);

        if ($lexeme === null) {
            return $this->firstMeeting($answer, $day, $now);
        }

        if ($lexeme->last_attempt_on?->toDateString() === $day) {
            return WordScoreOutcome::skipped(WordScoreReason::AlreadyToday, $this->visibleOn($now));
        }

        $changes = ['last_attempt_on' => StoredDate::of($day)];

        if ($answer->verdict !== AnswerVerdict::Passed) {
            $this->updateLexeme($answer, $changes);

            return WordScoreOutcome::skipped(WordScoreReason::Closed, $this->visibleOn($now));
        }

        $paidDue = $lexeme->last_credit_due_at === null ? null : CarbonImmutable::instance($lexeme->last_credit_due_at)->utc();

        if ($lexeme->confirmed_on === null) {
            $changes += $this->confirmation($answer, $paidDue, $now);

            return $this->credit($answer, ScoreKind::New, $day, $now, $changes, $lexeme);
        }

        [$reason, $due] = $this->repetition($answer->before(), $paidDue, $day, $now);
        if ($reason !== null || $due === null) {
            $this->updateLexeme($answer, $changes);

            return WordScoreOutcome::skipped($reason ?? WordScoreReason::NotDue, $this->visibleOn($now));
        }

        $changes['last_credit_due_at'] = self::timestamp($due);

        return $this->credit($answer, ScoreKind::Review, $day, $now, $changes, $lexeme);
    }

    /**
     * The very first attempt of the learner on a lexeme: the row is inserted with its final values (nothing else can be written to it
     * by this answer: the first credit of a lexeme is always `new`), so the INSERT replaces the SELECT-then-UPDATE. A parallel answer that
     * inserted it a moment earlier makes this INSERT a no-op: the row is then locked and the ordinary path follows.
     */
    private function firstMeeting(ScoredAnswer $answer, string $day, CarbonImmutable $now): WordScoreOutcome
    {
        $row = [
            'learner_id' => $answer->learnerId,
            'lexeme_id' => $answer->lexemeId,
            'last_attempt_on' => StoredDate::of($day),
        ];
        if ($answer->verdict === AnswerVerdict::Passed) {
            $row += $this->confirmation($answer, null, $now);
        }

        if (LearnerLexeme::query()->insertOrIgnore($row) === 0) {
            return $this->record($answer); // somebody created it in between: it is there now, locked by the ordinary path
        }

        if ($answer->verdict !== AnswerVerdict::Passed) {
            return WordScoreOutcome::skipped(WordScoreReason::Closed, $this->visibleOn($now));
        }

        return $this->credit($answer, ScoreKind::New, $day, $now, [], null);
    }

    /**
     * The columns of the first confirmation of a new word. A day-based card whose scheduled due has come also has this due paid for by
     * the confirmation (docs/32 §3.3 step 1): the same cycle does not bring a repetition on top.
     *
     * @return array<string, string>
     */
    private function confirmation(ScoredAnswer $answer, ?CarbonImmutable $paidDue, CarbonImmutable $now): array
    {
        $changes = [
            'confirmed_on' => StoredDate::of($this->rules->ratingDay($now)->toDateString()),
            'confirmed_source' => ($answer->source === ScoreSource::Game ? LexemeConfirmSource::Game : LexemeConfirmSource::Training)->value,
        ];

        $due = $answer->before()?->nominalDue();
        if ($due !== null && $due->lte($now->addSeconds(self::DUE_SLACK_SECONDS)) && ($paidDue === null || $due->gt($paidDue))) {
            $changes['last_credit_due_at'] = self::timestamp($due);
        }

        return $changes;
    }

    /**
     * A repetition of a confirmed lexeme (docs/32 §3.3 step 2): the scheduled due it pays for, or why it pays nothing.
     *
     * @return array{0: WordScoreReason|null, 1: CarbonImmutable|null}
     */
    private function repetition(?CardStateBefore $before, ?CarbonImmutable $paidDue, string $day, CarbonImmutable $now): array
    {
        $due = $before?->nominalDue();
        if ($before === null || $due === null || $before->lastReviewedAt === null) {
            return [WordScoreReason::NotDue, null];
        }
        if ($due->gt($now->addSeconds(self::DUE_SLACK_SECONDS))) {
            return [WordScoreReason::Early, null];
        }
        if ($paidDue !== null && $due->lte($paidDue)) {
            return [WordScoreReason::SameCycle, null];
        }
        // "Another rating day after the previous study" (docs/28): a card graded earlier the same rating day is not a repetition.
        if ($this->rules->ratingDay($before->lastReviewedAt)->toDateString() >= $day) {
            return [WordScoreReason::Early, null];
        }

        return [null, $due];
    }

    /**
     * The credit itself: the day row, the journal, the two counts, then the lexeme row once.
     *
     * @param  array<string, string>  $lexemeChanges  what the decision changes in the lexeme row (empty when it was just inserted)
     */
    private function credit(ScoredAnswer $answer, ScoreKind $kind, string $day, CarbonImmutable $now, array $lexemeChanges, ?LearnerLexeme $lexeme): WordScoreOutcome
    {
        [$row, $firstOfWeek, $firstOfDay] = $this->lockDay($answer, $day, $now);

        $weekVersion = (int) $row->week_version;
        $monthVersion = (int) $row->month_version;
        $reference = $this->referenceRules($weekVersion, $monthVersion);

        if ($kind === ScoreKind::Review && ($answer->before()->intervalDays ?? 0) >= $reference->longIntervalDays) {
            $kind = ScoreKind::Long;
        }

        $base = $reference->pointsFor($kind);
        $stamp = self::timestamp($now);
        $dayChanges = [
            'raw_points' => (int) $row->raw_points + $base,
            'new_count' => (int) $row->new_count + ($kind === ScoreKind::New ? 1 : 0),
            'review_count' => (int) $row->review_count + ($kind === ScoreKind::New ? 0 : 1),
            'long_count' => (int) $row->long_count + ($kind === ScoreKind::Long ? 1 : 0),
            'updated_at' => $stamp,
        ];

        $added = [];
        $counts = [];
        $anyV2 = false;
        foreach ([ScorePeriod::Week, ScorePeriod::Month] as $period) {
            $prefix = $period->value;
            $version = $period === ScorePeriod::Week ? $weekVersion : $monthVersion;
            $category = self::category($row->getAttribute("{$prefix}_category"));
            $rules = $version >= 2 ? $this->rules->version($version) : null;

            $before = new ScoreCount(
                points: (int) $row->getAttribute("{$prefix}_points"),
                new: (int) $row->getAttribute("{$prefix}_new"),
                review: (int) $row->getAttribute("{$prefix}_review"),
                long: (int) $row->getAttribute("{$prefix}_long"),
                cappedAt: $row->getAttribute("{$prefix}_capped_at") === null ? null : 'set',
            );
            $added[$prefix] = 0;

            if ($rules === null) {
                $counts[$prefix] = new ScoreDayCount($category, $version, $before->points, null, $before->new, null, $before->review, null);

                continue;
            }

            // The rule of one credit is the pure ScoreCount::add - the very one the correction of a day replays (S11).
            $anyV2 = true;
            $limits = $rules->limitsFor($category);
            [$added[$prefix], $after] = $before->add($kind, $rules, $category, $stamp);
            if ($after !== $before) {
                $dayChanges["{$prefix}_points"] = $after->points;
                $dayChanges[$kind === ScoreKind::New ? "{$prefix}_new" : "{$prefix}_review"] = $kind === ScoreKind::New ? $after->new : $after->review;
                if ($after->long !== $before->long) {
                    $dayChanges["{$prefix}_long"] = $after->long;
                }
                if ($before->cappedAt === null && $after->cappedAt !== null) {
                    $dayChanges["{$prefix}_capped_at"] = $after->cappedAt;
                }
            }

            $counts[$prefix] = new ScoreDayCount($category, $version, $after->points, $limits->dayCap, $after->new, $limits->new, $after->review, $limits->review);
        }

        $journaled = ScoreCredit::query()->insertOrIgnore([
            'learner_id' => $answer->learnerId,
            'rating_day' => StoredDate::of($day),
            'lexeme_id' => $answer->lexemeId,
            'word_id' => $answer->wordId,
            'source' => $answer->source->value,
            'source_id' => $answer->sourceId,
            'kind' => $kind->value,
            'week_points' => $added['week'],
            'month_points' => $added['month'],
            'created_at' => self::timestamp($now),
        ]) > 0;

        if (! $journaled) {
            // The lexeme has a credit of this day already: the lock above makes it impossible, the unique index is the last line. The day
            // stays as it is; only the attempt is marked, so the lexeme row agrees with the journal.
            if ($lexeme !== null) {
                $this->updateLexeme($answer, ['last_attempt_on' => StoredDate::of($day)]);
            }

            return WordScoreOutcome::skipped(WordScoreReason::AlreadyToday, $this->visibleOn($now));
        }

        LearnerScoreDay::query()
            ->where('learner_id', $answer->learnerId)
            ->where('rating_day', StoredDate::of($day))
            ->update($dayChanges);

        $newLong = false;
        if ($kind === ScoreKind::Long && ($added['week'] > 0 || $added['month'] > 0) && $lexeme !== null) {
            if ($lexeme->first_long_on === null) {
                $lexemeChanges['first_long_on'] = StoredDate::of($day);
                $newLong = true;
            }
            $monthCategory = $counts['month']->category;
            $column = $monthCategory === ScoreCategory::Pro ? 'long_pro_on' : 'long_free_on';
            if ($added['month'] > 0 && $lexeme->getAttribute($column) === null) {
                $lexemeChanges[$column] = StoredDate::of($day);
            }
        }

        if ($lexeme !== null && $lexemeChanges !== []) {
            $this->updateLexeme($answer, $lexemeChanges);
        }

        $cut = $anyV2 && $added['week'] === 0 && $added['month'] === 0;

        return new WordScoreOutcome(
            reason: $cut ? WordScoreReason::Capped : WordScoreReason::Credited,
            kind: $kind,
            base: $base,
            weekAdded: $added['week'],
            monthAdded: $added['month'],
            week: $counts['week'],
            month: $counts['month'],
            visible: $anyV2 && $this->rules->mode() === ScoringMode::Live,
            firstCreditOfWeek: $firstOfWeek,
            newLongLexeme: $newLong,
            firstCreditOfDay: $firstOfDay,
        );
    }

    /**
     * The day row of the rating day, locked; created on the first credit of the day with the category and the version of its week and
     * month (fixed here when they are not yet). A row inserted by this call is locked by its insert and known whole - it is not read
     * back; one a parallel answer inserted first is read again under the lock.
     *
     * @return array{0: LearnerScoreDay, 1: bool, 2: bool} the row, whether this call fixed the week, whether it created the row
     */
    private function lockDay(ScoredAnswer $answer, string $day, CarbonImmutable $now): array
    {
        $row = $this->lockedDay($answer->learnerId, $day);
        if ($row !== null) {
            return [$row, false, false];
        }

        $fixed = $this->periods->fixDay($answer->learnerId, CarbonImmutable::parse($day, $this->rules->timezone()), ScoreCategory::fromPlan($answer->plan()), $now);

        $attributes = [
            'learner_id' => $answer->learnerId,
            'rating_day' => StoredDate::of($day),
            'week_starts_on' => StoredDate::of($fixed['week']->startsOn),
            'week_category' => $fixed['week']->category->value,
            'week_version' => $fixed['week']->rulesVersion,
            'month_starts_on' => StoredDate::of($fixed['month']->startsOn),
            'month_category' => $fixed['month']->category->value,
            'month_version' => $fixed['month']->rulesVersion,
            'raw_points' => 0, 'new_count' => 0, 'review_count' => 0, 'long_count' => 0,
            'week_points' => 0, 'week_new' => 0, 'week_review' => 0, 'week_long' => 0, 'week_capped_at' => null,
            'month_points' => 0, 'month_new' => 0, 'month_review' => 0, 'month_long' => 0, 'month_capped_at' => null,
            'revision' => 0,
            'created_at' => self::timestamp($now),
            'updated_at' => self::timestamp($now),
        ];

        if (LearnerScoreDay::query()->insertOrIgnore($attributes) > 0) {
            return [(new LearnerScoreDay)->newFromBuilder($attributes), $fixed['week']->created, true];
        }

        $row = $this->lockedDay($answer->learnerId, $day)
            ?? throw new LogicException("learner_score_days: no row of learner {$answer->learnerId} on {$day} after the insert.");

        return [$row, $fixed['week']->created, false];
    }

    private static function category(mixed $value): ScoreCategory
    {
        return $value instanceof ScoreCategory ? $value : ScoreCategory::from((string) $value);
    }

    private function lockedDay(int $learnerId, string $day): ?LearnerScoreDay
    {
        /** @var LearnerScoreDay|null $row */
        $row = LearnerScoreDay::query()
            ->where('learner_id', $learnerId)
            ->where('rating_day', StoredDate::of($day))
            ->lockForUpdate()
            ->first();

        return $row;
    }

    private function lockLexeme(int $learnerId, int $lexemeId): ?LearnerLexeme
    {
        /** @var LearnerLexeme|null $row */
        $row = LearnerLexeme::query()
            ->where('learner_id', $learnerId)
            ->where('lexeme_id', $lexemeId)
            ->lockForUpdate()
            ->first();

        return $row;
    }

    /** @param array<string, string> $changes */
    private function updateLexeme(ScoredAnswer $answer, array $changes): void
    {
        LearnerLexeme::query()
            ->where('learner_id', $answer->learnerId)
            ->where('lexeme_id', $answer->lexemeId)
            ->update($changes);
    }

    /**
     * The rules the kind and the raw points of a credit are told by: the higher version of the day's two counts, or - when both are still
     * on version 1 (`shadow`, `live` before the start) - the latest configured version, so that the recorded facts are those of the
     * rules to come. {@see records()} guarantees one exists.
     */
    private function referenceRules(int $weekVersion, int $monthVersion): ScoreRuleVersion
    {
        return $this->rules->reference($weekVersion, $monthVersion)
            ?? throw new LogicException('The word points are recorded without a configured rules version.');
    }

    /** Whether an answer of this moment may be shown: `live` and the week or the month of the moment counts by a version >= 2. */
    private function visibleOn(CarbonImmutable $now): bool
    {
        if ($this->rules->mode() !== ScoringMode::Live) {
            return false;
        }

        $day = $this->rules->ratingDay($now);

        return $this->rules->versionFor($this->rules->periodStart(ScorePeriod::Week, $day)) >= 2
            || $this->rules->versionFor($this->rules->periodStart(ScorePeriod::Month, $day)) >= 2;
    }

    /** A moment as the `timestamp` columns store it (UTC, the connection's format). */
    private static function timestamp(CarbonImmutable $moment): string
    {
        return (string) (new LearnerLexeme)->fromDateTime($moment->setTimezone('UTC'));
    }
}
