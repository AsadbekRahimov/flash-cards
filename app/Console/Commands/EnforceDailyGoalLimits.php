<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Learners\Services\DailyGoalLimiter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `learners:enforce-goal-limits` (docs/34 §2б.2): lowers the daily goal of learners whose plan no longer allows it (Pro ended) and who
 * did not open the Mini App since - the reminders and the bot's `/today` read the goal too. Lowering only: a goal comes back when the
 * learner opens a screen with Pro again. Daily at 03:00 UTC, `withoutOverlapping`; a repeated or parallel run changes nothing
 * ({@see DailyGoalLimiter::enforce()} re-checks under the row lock).
 */
final class EnforceDailyGoalLimits extends Command
{
    /** @var string */
    protected $signature = 'learners:enforce-goal-limits';

    /** @var string */
    protected $description = 'Lower the daily goal of learners whose plan no longer allows it (Pro ended).';

    public function handle(DailyGoalLimiter $goals): int
    {
        $report = $goals->enforceAll(CarbonImmutable::now());

        $this->info("Daily goals checked: {$report['checked']}, lowered: {$report['lowered']}.");

        return self::SUCCESS;
    }
}
