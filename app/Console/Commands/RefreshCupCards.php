<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Cup\Services\CupCards;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The cards of the friends' cups in their chats (docs/33 §3.2, §6.3): every five minutes, the cards with news since their last render
 * (new day scores, participants, a new day, a change of the cup itself) - picked by ONE statement - are edited in place when their text
 * changed, each at most once in five minutes. "message is not modified" is a success; a deleted card is posted anew at most once a day.
 */
final class RefreshCupCards extends Command
{
    /** @var string */
    protected $signature = 'cups:refresh-cards {--batch=200 : cards per run}';

    /** @var string */
    protected $description = 'Edit the cards of the friends\' cups that have new results.';

    public function handle(CupCards $cards): int
    {
        $batch = $this->option('batch');
        $report = $cards->refreshDue(CarbonImmutable::now(), is_numeric($batch) ? max(1, (int) $batch) : 200);

        $this->info(collect($report)->map(static fn (int $n, string $k): string => "{$k}: {$n}")->implode(', '));

        return self::SUCCESS;
    }
}
