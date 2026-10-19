<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Learning\Services\TrainingSessionService;
use Illuminate\Console\Command;

/**
 * Closes open training sessions past their lifetime with `done_reason = expired`:
 * personal ones by `expires_at`, group ones by age (docs/12 §7.6).
 *
 * Scheduled `everyFifteenMinutes()->withoutOverlapping()`; a repeated run is a no-op.
 */
final class CloseExpiredTrainingSessions extends Command
{
    /** @var string */
    protected $signature = 'training:close-expired';

    /** @var string */
    protected $description = 'Close training sessions whose lifetime has passed.';

    public function handle(TrainingSessionService $sessions): int
    {
        $closed = $sessions->closeExpired();

        $this->info("Training sessions closed: {$closed}");

        return self::SUCCESS;
    }
}
