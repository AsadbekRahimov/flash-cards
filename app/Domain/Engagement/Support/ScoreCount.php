<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Support;

use App\Domain\Engagement\DTO\ScoreRuleVersion;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Engagement\Services\Scoring\ScoreCorrectionService;
use App\Domain\Engagement\Services\Scoring\WordScoreService;

/**
 * One count (weekly or monthly) of a rating day (docs/32 §3.4) as a value, and the pure rule that adds one credit to it - shared by
 * the answer ({@see WordScoreService}) and the correction of a day ({@see ScoreCorrectionService}, which replays the credits of the day
 * that are not voided, in the order of their ids). No statement, no clock: the caller passes the moment the credit happened.
 *
 *   - the slot of the kind (`new` / repetitions: `review` and `long` share one) is spent while the category's limit is not reached -
 *     even when the cap leaves 0 points ("the first suitable answers up to the limit", docs/28); past the limit nothing changes;
 *   - the points are what is left under the day cap (98 of 100 and a 5 → +2);
 *   - `long` counts the lexemes whose long repetition really added points to this count;
 *   - `cappedAt` is the moment the count first reached the cap (kept once set).
 */
final readonly class ScoreCount
{
    public function __construct(
        public int $points = 0,
        public int $new = 0,
        public int $review = 0,
        public int $long = 0,
        public ?string $cappedAt = null,
    ) {}

    /**
     * The count after one credit of `$kind` at `$at` (a stored timestamp) under the rules and the category of the period.
     *
     * @return array{0: int, 1: self} the points the credit added, the count after it
     */
    public function add(ScoreKind $kind, ScoreRuleVersion $rules, ScoreCategory $category, string $at): array
    {
        $limits = $rules->limitsFor($category);
        $isNew = $kind === ScoreKind::New;

        if (($isNew ? $this->new : $this->review) >= ($isNew ? $limits->new : $limits->review)) {
            return [0, $this];
        }

        $added = min($rules->pointsFor($kind), max(0, $limits->dayCap - $this->points));
        $points = $this->points + $added;

        return [$added, new self(
            points: $points,
            new: $this->new + ($isNew ? 1 : 0),
            review: $this->review + ($isNew ? 0 : 1),
            long: $this->long + ($kind === ScoreKind::Long && $added > 0 ? 1 : 0),
            cappedAt: $this->cappedAt ?? ($points >= $limits->dayCap ? $at : null),
        )];
    }

    /**
     * The count of a day rebuilt from its credits, oldest first: what each one adds and the count at the end.
     *
     * @param  list<array{kind: ScoreKind, at: string}>  $credits  in the order of their ids
     * @return array{0: list<int>, 1: self} the points of every credit (same order), the count after the last
     */
    public static function replay(array $credits, ScoreRuleVersion $rules, ScoreCategory $category): array
    {
        $count = new self;
        $added = [];
        foreach ($credits as $credit) {
            [$points, $count] = $count->add($credit['kind'], $rules, $category, $credit['at']);
            $added[] = $points;
        }

        return [$added, $count];
    }
}
