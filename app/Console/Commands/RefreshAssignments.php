<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organizations\Services\AssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Every 30 minutes (docs/13 §1.4): recounts the progress snapshots of active assignments, posts the
 * 24-hour reminders to the groups, retries announcements Telegram refused, and closes assignments
 * overdue by more than the grace period. Every step is idempotent (conditional UPDATEs), so a repeated
 * or overlapping run changes nothing twice.
 */
final class RefreshAssignments extends Command
{
    /** @var string */
    protected $signature = 'assignments:refresh';

    /** @var string */
    protected $description = 'Recount assignment progress, post due reminders to groups, close long-overdue assignments.';

    public function handle(AssignmentService $assignments): int
    {
        $now = CarbonImmutable::now();

        $announced = $assignments->announcePending($now);
        $refreshed = $assignments->refreshActive($now);
        $reminded = $assignments->remindDue($now);
        $closed = $assignments->closeExpired($now);

        $this->info("Assignments refreshed: {$refreshed}, announced: {$announced}, reminders: {$reminded}, closed: {$closed}");

        return self::SUCCESS;
    }
}
