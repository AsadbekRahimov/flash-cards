<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Organizations\Services\AssignmentService;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use App\Models\Assignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Posts the announcement of a new assignment to its group chat (docs/13 §2.3). Dispatched after the
 * commit of `AssignmentService::create`; the post is claimed by `announced_at` inside the service,
 * so a retry or a duplicate delivery never announces twice. No retries of its own: a refused post
 * is retried by `assignments:refresh` (bounded attempts).
 */
final class AnnounceAssignmentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    // No retries after a failure (maxExceptions = 1), but a release for lack of a global Telegram slot
    // uses up an attempt, so attempts themselves are unlimited.
    public int $tries = 0;

    public int $maxExceptions = 1;

    public function __construct(public readonly int $assignmentId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleTelegramOutbound];
    }

    public function handle(AssignmentService $assignments): void
    {
        $assignment = Assignment::query()->find($this->assignmentId);

        if ($assignment !== null) {
            // The slot is taken by the middleware.
            $assignments->announce($assignment, reserveSlot: false);
        }
    }
}
