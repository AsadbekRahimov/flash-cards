<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The winners of a month of the rating were confirmed by an administrator (docs/13 §7.6). Raised AFTER the commit of the
 * confirmation, once per month. The bot's congratulation DMs hang on it (a listener that queues one message per winner;
 * texts `lang/<locale>/leagues.php` `month.won`); everybody else sees the result in the Mini App — no mass mailing.
 *
 * `category` - the table (free / pro) of a v2 month; absent in a v1 month.
 *
 * @phpstan-type MonthWinner array{learner_id: int, place: int, points: int, category?: string}
 */
final class LeagueMonthConfirmed
{
    use Dispatchable;

    /**
     * @param  string  $startsOn  `Y-m-01` of the month
     * @param  list<MonthWinner>  $winners  by place (1 first)
     */
    public function __construct(
        public readonly int $monthId,
        public readonly string $startsOn,
        public readonly array $winners,
    ) {}
}
