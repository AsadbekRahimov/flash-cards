<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Enums\ScoreCategory;

/**
 * What `GET /api/twa/league/all-time` shows (docs/04 A.19a, docs/32 §8.3, §12.11): one table of "all time" - a category (`?category=`,
 * else the viewer's own: the category of their current month, else of their plan), the whole of it or a region (`?scope=region`,
 * `&region=`) - its top with places, the viewer's own line and neighbours when they have a place in it, the cursor of the next page,
 * and the viewer's personal total over both categories (`myTotal`, `byCategory`; the opt-out does not hide it from its owner).
 * `enabled` false (the word points not `live`, the leagues off): nothing else is filled.
 */
final readonly class AllTimeOverview
{
    /**
     * @param  list<BoardRow>  $top
     * @param  array{above: list<BoardRow>, below: list<BoardRow>, rank: int, next_gap: int|null}|null  $neighbours
     * @param  array<string, int>  $byCategory  category => points of the viewer's rows
     */
    public function __construct(
        public bool $enabled,
        public ?string $countedSince = null,
        public ?ScoreCategory $category = null,
        public ?ScoreCategory $myCategory = null,
        public ?RegionChoice $choice = null,
        public ?RegionStanding $region = null,
        public int $participants = 0,
        public array $top = [],
        public ?AllTimeLine $me = null,
        public ?array $neighbours = null,
        public ?BoardCursor $nextCursor = null,
        public bool $readOnly = true,
        public int $myTotal = 0,
        public array $byCategory = [],
        public bool $optedOut = false,
    ) {}
}
