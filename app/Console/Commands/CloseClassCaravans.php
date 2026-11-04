<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Game\Services\Class\ClassCaravanService;
use Illuminate\Console\Command;

/**
 * Closes the class caravans past their `ends_at` (`finished`, `active_group_id` released) and queues the summary of each
 * (`PostClassCaravanSummaryJob`), docs/18 §12.11. Scheduled `everyFifteenMinutes()->withoutOverlapping()`; one transaction per
 * caravan, read again under its lock, so a repeated or parallel run closes nothing twice and posts nothing twice.
 */
final class CloseClassCaravans extends Command
{
    /** @var string */
    protected $signature = 'game:class-caravans-close';

    /** @var string */
    protected $description = 'Close the class caravans whose week has ended and queue their summaries.';

    public function handle(ClassCaravanService $caravans): int
    {
        $closed = $caravans->closeDue();

        $this->info("Class caravans closed: {$closed}");

        return self::SUCCESS;
    }
}
