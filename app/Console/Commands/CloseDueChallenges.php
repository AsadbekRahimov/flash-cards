<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Engagement\Services\ChallengeService;
use Illuminate\Console\Command;

/**
 * Closes friend challenges whose period is over (scores from `learner_daily_stats`, one result and one pair of
 * DMs each) and expires the pending ones nobody answered (docs/13 §7.5). Scheduled
 * `everyFifteenMinutes()->withoutOverlapping()`; a repeated or parallel run changes nothing twice.
 */
final class CloseDueChallenges extends Command
{
    /** @var string */
    protected $signature = 'challenges:close-due';

    /** @var string */
    protected $description = 'Close finished friend challenges and expire unanswered ones.';

    public function handle(ChallengeService $challenges): int
    {
        $changed = $challenges->closeDue();

        $this->info("Challenges closed or expired: {$changed}");

        return self::SUCCESS;
    }
}
