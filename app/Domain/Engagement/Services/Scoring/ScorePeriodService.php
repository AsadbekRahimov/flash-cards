<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services\Scoring;

use App\Domain\Engagement\DTO\FixedScorePeriod;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScorePeriod;
use App\Domain\Learners\Support\StoredDate;
use App\Models\LearnerScorePeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;

/**
 * Fixes the category (Free / Pro) and the rules version of a learner's week and month (docs/28 «Смена тарифа», docs/32 §2.3):
 * ONCE, at the first credit of the period, from the plan in force at that moment; never changed afterwards. A plan change in the
 * middle of a period - an upgrade, the end of a trial or of a subscription, a seat given or taken by an organisation - moves only
 * the periods that start later: Pro bought on Thursday leaves this week and this month in Free, the next week starts in Pro while
 * the month is still Free. The week and the month are fixed independently.
 *
 * The caller passes the category of the plan it already holds (`ScoreCategory::fromPlan($entitlement->plan)`); the version comes
 * from {@see ScoreRules::versionFor()} at the fixing moment. Meant to run inside the transaction of the answer (the scoring core,
 * S4), right before the day row of the learner is created:
 *
 *   - one read of both rows by primary key; both there (every credit day but the first of a period) = done;
 *   - each missing one is inserted with `INSERT … ON CONFLICT DO NOTHING` (its own statement: the affected count tells whether
 *     THIS call fixed it) and the missing ones are read again - 3 statements on the first credit of a week or a month, 4 on a
 *     day that starts both. Two requests fixing the same period
 *     at once meet on the primary key: the second waits for the first's commit, inserts nothing and reads the first's row - the
 *     first one wins, whatever category the second asked for.
 *
 * The dates are written through {@see StoredDate} (the representation the `date` columns compare by on sqlite too).
 */
final class ScorePeriodService
{
    public function __construct(private readonly ScoreRules $rules) {}

    /**
     * The fixed week and month of the rating day `$day` (league zone), fixing whichever is not fixed yet with `$category`.
     *
     * @return array{week: FixedScorePeriod, month: FixedScorePeriod}
     */
    public function fixDay(int $learnerId, CarbonInterface $day, ScoreCategory $category, CarbonImmutable $now): array
    {
        $starts = [
            ScorePeriod::Week->value => $this->rules->periodStart(ScorePeriod::Week, $day)->toDateString(),
            ScorePeriod::Month->value => $this->rules->periodStart(ScorePeriod::Month, $day)->toDateString(),
        ];

        $rows = $this->read($learnerId, $starts);

        /** @var array<string, bool> $created period => fixed by this call */
        $created = [];
        foreach (array_diff_key($starts, $rows) as $period => $startsOn) {
            $created[$period] = $this->insert($learnerId, $period, $startsOn, $category, $now);
        }
        if ($created !== []) {
            $rows = [...$rows, ...$this->read($learnerId, array_intersect_key($starts, $created))];
        }

        $out = [];
        foreach ($starts as $period => $startsOn) {
            $row = $rows[$period] ?? throw $this->vanished($learnerId, $period, $startsOn);
            $out[$period] = $this->toDto($row, created: $created[$period] ?? false);
        }

        return ['week' => $out[ScorePeriod::Week->value], 'month' => $out[ScorePeriod::Month->value]];
    }

    /** One period of the rating day `$day` (league zone), fixed with `$category` when it is not fixed yet. */
    public function fix(int $learnerId, ScorePeriod $period, CarbonInterface $day, ScoreCategory $category, CarbonImmutable $now): FixedScorePeriod
    {
        $startsOn = $this->rules->periodStart($period, $day)->toDateString();

        $row = $this->read($learnerId, [$period->value => $startsOn])[$period->value] ?? null;
        if ($row !== null) {
            return $this->toDto($row, created: false);
        }

        $inserted = $this->insert($learnerId, $period->value, $startsOn, $category, $now);

        $row = $this->read($learnerId, [$period->value => $startsOn])[$period->value]
            ?? throw $this->vanished($learnerId, $period->value, $startsOn);

        return $this->toDto($row, created: $inserted);
    }

    /** The fixed period of a learner, or null when nothing was credited in it yet. */
    public function find(int $learnerId, ScorePeriod $period, CarbonInterface $day): ?FixedScorePeriod
    {
        $startsOn = $this->rules->periodStart($period, $day)->toDateString();
        $row = $this->read($learnerId, [$period->value => $startsOn])[$period->value] ?? null;

        return $row === null ? null : $this->toDto($row, created: false);
    }

    /** `INSERT … ON CONFLICT DO NOTHING` of one period; true = this statement created it. */
    private function insert(int $learnerId, string $period, string $startsOn, ScoreCategory $category, CarbonImmutable $now): bool
    {
        return LearnerScorePeriod::query()->insertOrIgnore([
            'learner_id' => $learnerId,
            'period' => $period,
            'starts_on' => StoredDate::of($startsOn),
            'category' => $category->value,
            'rules_version' => $this->rules->versionFor($startsOn),
            'fixed_at' => $now,
        ]) > 0;
    }

    /** A row inserted or found a statement ago is gone: only a learner purged meanwhile (cascade) can do that. */
    private function vanished(int $learnerId, string $period, string $startsOn): LogicException
    {
        return new LogicException("learner_score_periods: no row of learner {$learnerId}, {$period} {$startsOn} after the insert.");
    }

    /**
     * The rows of the given periods, one statement.
     *
     * @param  array<string, string>  $starts  period => first day `Y-m-d`
     * @return array<string, LearnerScorePeriod> period => row (only the existing ones)
     */
    private function read(int $learnerId, array $starts): array
    {
        $rows = LearnerScorePeriod::query()
            ->where('learner_id', $learnerId)
            ->where(function ($query) use ($starts): void {
                foreach ($starts as $period => $startsOn) {
                    $query->orWhere(fn ($one) => $one->where('period', $period)->where('starts_on', StoredDate::of($startsOn)));
                }
            })
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->period->value] = $row;
        }

        return $out;
    }

    private function toDto(LearnerScorePeriod $row, bool $created): FixedScorePeriod
    {
        return new FixedScorePeriod(
            period: $row->period,
            startsOn: $row->starts_on->toDateString(),
            category: $row->category,
            rulesVersion: $row->rules_version,
            created: $created,
        );
    }
}
