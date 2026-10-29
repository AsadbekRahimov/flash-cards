<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Practice\Services\PracticeSessionService;
use Illuminate\Console\Command;

/**
 * Closes the solo practice sessions past their `expires_at` (`finished` / `expired`, `active_learner_id` released, the event
 * `practice.session_finished` once), docs/16 §6.1, §8.6.
 *
 * Scheduled `everyFifteenMinutes()->withoutOverlapping()`; a repeated or parallel run is harmless (one conditional UPDATE
 * per session, claimed by whoever gets there first).
 */
final class ClosePracticeSessions extends Command
{
    /** @var string */
    protected $signature = 'practice:close-expired';

    /** @var string */
    protected $description = 'Close practice sessions whose lifetime has passed.';

    public function handle(PracticeSessionService $sessions): int
    {
        $closed = $sessions->closeExpired();

        $this->info("Practice sessions closed: {$closed}");

        return self::SUCCESS;
    }
}
