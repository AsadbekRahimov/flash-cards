<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Organizations\Services\AssignmentService;
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

    public int $tries = 1;

    public function __construct(public readonly int $assignmentId) {}

    public function handle(AssignmentService $assignments): void
    {
        $assignment = Assignment::query()->find($this->assignmentId);

        if ($assignment !== null) {
            $assignments->announce($assignment);
        }
    }
}
