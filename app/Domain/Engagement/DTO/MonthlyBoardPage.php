<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Enums\ScoreCategory;

/**
 * A page of one table of a v2 month after a cursor (`GET /api/twa/league/month?category=&after=`, docs/04 A.19, docs/32 §5.3): the
 * rows with their competition places and the emoji of Pro, and where the next page continues. `readOnly` - the table is not the
 * viewer's own (Free ↔ Pro): it is shown, the viewer has no place in it.
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
    ) {}
}
