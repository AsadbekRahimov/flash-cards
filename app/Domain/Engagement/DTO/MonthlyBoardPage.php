<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Enums\ScoreCategory;

/**
 * A page of one table of the word points v2 after a cursor (docs/04 A.19, A.19a, docs/32 §5.3): the rows with their competition places
 * and the emoji of Pro, and where the next page continues. The table: a category of a v2 month (`monthId`), or of "all time"
 * (`allTime`, `monthId` 0) - the whole category, or a region of it (`region`, docs/35 §2.4; a region below the threshold has no rows).
 * `readOnly` - the viewer has no place in this table (the other category, another region): it is shown, the viewer is not in it.
 */
final readonly class MonthlyBoardPage
{
    /** @param  list<BoardRow>  $rows */
    public function __construct(
        public int $monthId,
        public ScoreCategory $category,
        public bool $readOnly,
        public array $rows,
        public ?BoardCursor $next,
        public ?RegionStanding $region = null,
        public bool $allTime = false,
    ) {}

    /** The `?after=` of the next page, bound to this table; null on the last one. */
    public function nextCursor(): ?string
    {
        if ($this->next === null) {
            return null;
        }

        return $this->allTime
            ? BoardPageCursor::encodeAllTime($this->category, $this->next, $this->region?->code)
            : BoardPageCursor::encode($this->monthId, $this->category, $this->next, $this->region?->code);
    }
}
