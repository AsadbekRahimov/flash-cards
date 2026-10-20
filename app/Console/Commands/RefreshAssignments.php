<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organizations\Services\AssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

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

        $failed = false;
        $step = static function (callable $run) use (&$failed): int {
            try {
                return (int) $run();
            } catch (Throwable $e) {
                // One failing step (a database hiccup, a broken chat) must not skip the others.
                report($e);
                $failed = true;

                return 0;
            }
        };

        $announced = $step(fn (): int => $assignments->announcePending($now));
        $refreshed = $step(fn (): int => $assignments->refreshActive($now));
        $reminded = $step(fn (): int => $assignments->remindDue($now));
        $closed = $step(fn (): int => $assignments->closeExpired($now));

        $this->info("Assignments refreshed: {$refreshed}, announced: {$announced}, reminders: {$reminded}, closed: {$closed}");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
