<?php

declare(strict_types=1);

namespace App\Domain\Polls\Services;

use App\Domain\Polls\Enums\PollStatus;
use App\Models\Poll;
use Carbon\CarbonImmutable;

/**
 * `polls:close-due` (docs/35 O7), every 5 minutes: the open polls whose `closes_at` has come are closed by
 * {@see PollLauncher::close()} with no administrator - the status, the skipped rest of the broadcast, `results_snapshot` and the audit
 * record `poll.closed` (user empty). The taps answer "closed" from `closes_at` on by themselves ({@see PollVoteService}); this only
 * makes it final.
 *
 * One read along `polls_close_due_idx` (its predicate is exactly the WHERE below), then one closing per poll. Idempotent: a poll
 * closed meanwhile - by hand or by a parallel run - is skipped by the launcher under its row lock.
 */
final class PollCloser
{
    public function __construct(private readonly PollLauncher $launcher) {}

    /** @return int the polls this run closed */
    public function closeDue(?CarbonImmutable $now = null): int
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $closed = 0;

        $due = Poll::query()
            ->whereIn('status', [PollStatus::Sending->value, PollStatus::Sent->value])
            ->whereNotNull('closes_at')
            ->where('closes_at', '<=', $now)
            ->orderBy('closes_at')
            ->get();

        foreach ($due as $poll) {
            if ($this->launcher->close($poll, null, $now)) {
                $closed++;
            }
        }

        return $closed;
    }
}
