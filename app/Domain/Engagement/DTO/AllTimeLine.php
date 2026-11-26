<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Enums\ScoreCategory;

/**
 * The viewer's own line of a table of "all time" (docs/32 §5, §8.3): their row of the category shown - the competition place among the
 * participants of the category (null while not a participant: opted out, blocked), the points and the tie-break facts, the first and
 * the last rating day, the place in their region (`region`, docs/35 §2.4: the CURRENT region; null without one) and the gap to the next
 * place of the table shown (null for the leader, or when the viewer has no place in it).
 */
final readonly class AllTimeLine
{
    /** @param  MonthlyMyLine::REASON_*|null  $notCountedReason */
    public function __construct(
        public ScoreCategory $category,
        public ?int $rank,
        public int $points,
        public int $longWords,
        public int $activeDays,
        public bool $participant,
        public ?string $notCountedReason,
        public string $firstDay,
        public string $lastDay,
        public ?RegionStanding $region,
        public ?int $nextGap,
    ) {}
}
