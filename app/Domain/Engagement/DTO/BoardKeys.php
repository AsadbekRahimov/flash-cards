<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Services\Scoring\BoardReader;

/**
 * The cache keys of one table of the word points v2 ({@see BoardReader}, docs/19 §3: a version in the key, arrays of scalars, a TTL,
 * dropped on write):
 *
 *   top     the first rows (arrays of {@see BoardRow::toArray()} with the emoji of Pro);
 *   count   the number of participants (an int);
 *   rank    `{rank}:{points}:{long_words}:{active_days}` - the competition place of a tuple (an int): the key holds the tuple, so the
 *           viewer's own credit reads a new key at once and only the moves of the others lag by the TTL;
 *   near    `{near}:{learner}:{points}:{long_words}:{active_days}` - the viewer's neighbours, place and gap.
 *
 * The tables:
 *
 *   a region of a v2 month   league:month:{top:v2|count:v1|rank:v1|near:v1}:{month}:{category}:r:{region}
 *   all time                 league:alltime:{top|count|rank|near}:v1:{category}
 *   a region of all time     league:alltime:{top|count|rank|near}:v1:{category}:r:{region}
 *
 * (The whole category of a month keeps its keys of S12: MonthlyRatingService::topKey(), countKey(), RANK_PREFIX, NEAR_PREFIX.) A
 * segment `r:` can never be taken for a number, so the keys of a region and of its category never meet.
 */
final readonly class BoardKeys
{
    public const ALL_TIME = 'league:alltime:';

    public function __construct(
        public string $top,
        public string $count,
        public string $rankPrefix,
        public string $nearPrefix,
    ) {}

    public static function monthRegion(int $monthId, ScoreCategory $category, string $region): self
    {
        $table = $monthId.':'.$category->value.':r:'.$region;

        return new self(
            'league:month:top:v2:'.$table,
            'league:month:count:v1:'.$table,
            'league:month:rank:v1:'.$table,
            'league:month:near:v1:'.$table,
        );
    }

    public static function allTime(ScoreCategory $category, ?string $region = null): self
    {
        $table = 'v1:'.$category->value.($region === null ? '' : ':r:'.$region);

        return new self(
            self::ALL_TIME.'top:'.$table,
            self::ALL_TIME.'count:'.$table,
            self::ALL_TIME.'rank:'.$table,
            self::ALL_TIME.'near:'.$table,
        );
    }

    public function rank(int $points, int $longWords, int $activeDays): string
    {
        return $this->rankPrefix.':'.$points.':'.$longWords.':'.$activeDays;
    }

    public function near(int $learnerId, int $points, int $longWords, int $activeDays): string
    {
        return $this->nearPrefix.':'.$learnerId.':'.$points.':'.$longWords.':'.$activeDays;
    }

    /**
     * What a write to the table drops: the top and the number of participants (the places and neighbours expire by their TTL).
     *
     * @return list<string>
     */
    public function written(): array
    {
        return [$this->top, $this->count];
    }
}
