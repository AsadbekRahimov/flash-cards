<?php

declare(strict_types=1);

namespace App\Domain\Polls\Services;

use App\Domain\Polls\Enums\PollStatus;
use App\Jobs\SendPollBatchJob;
use App\Models\Poll;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `polls:sweep` (docs/35 O3), every 5 minutes: whatever a crash left of a broadcast converges here. Idempotent; a parallel or
 * repeated run changes nothing twice.
 *
 *  1. Rows stuck in `sending` longer than `polls.stuck_minutes` (a worker died between the step to `sending` and the result,
 *     a test send died) → `unknown`. Never sent again: the message may have arrived.
 *  2. A `sending` poll whose insert of recipients did not finish (`recipient_count` NULL, launched longer than
 *     {@see FILL_GRACE_MINUTES} ago) → the insert is resumed ({@see PollLauncher::fill()}), which also starts the chains.
 *  3. A `sending` poll past its deadline → the rows still `pending` become `skipped` (`expired`).
 *  4. A `sending` poll with pending rows and no lease younger than `polls.lease_seconds` → the chains of the slots that have no job
 *     waiting in the queue start again ({@see PollLauncher::startWorkers()}: the job is unique per poll and slot until a worker picks
 *     it up). A chain between batches - its successor delayed by a 429 or "no slot", or behind a long queue `low` - holds its slot,
 *     so it gets no twin; a dead chain (a worker killed, `maxExceptions` reached) holds nothing and comes back. A running batch
 *     renews a lease, so a healthy broadcast is not even looked at. `restarted` counts the polls where a job was queued.
 *  5. A poll stopped or closed within the last day with rows still `pending` (a range of the insert that committed right after
 *     the stop) → `skipped`.
 *  6. `sending → sent` where nothing is left.
 */
final class PollSweeper
{
    public const FILL_GRACE_MINUTES = 10;

    public function __construct(
        private readonly PollLauncher $launcher,
        private readonly PollDeliverySender $sender,
    ) {}

    /** @return array{unknown: int, resumed: int, expired: int, restarted: int, skipped: int, finished: int} */
    public function sweep(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $out = ['unknown' => 0, 'resumed' => 0, 'expired' => 0, 'restarted' => 0, 'skipped' => 0, 'finished' => 0];

        // 1. Along the partial index: only unsent rows are in it.
        $out['unknown'] = DB::update(
            "UPDATE poll_deliveries SET status = 'unknown', error_code = 'stuck' WHERE status = 'sending' AND claimed_at < ?",
            [$now->subMinutes(max(1, (int) config('polls.stuck_minutes', 15)))->format('Y-m-d H:i:s')],
        );

        $leaseEdge = $now->subSeconds(max(1, (int) config('polls.lease_seconds', 300)))->format('Y-m-d H:i:s');

        foreach (Poll::query()->where('status', PollStatus::Sending)->orderBy('id')->get() as $poll) {
            $launchedAt = CarbonImmutable::instance($poll->launched_at ?? $now)->utc();

            if ($poll->recipient_count === null) {
                if ($launchedAt->lessThan($now->subMinutes(self::FILL_GRACE_MINUTES)) && $this->launcher->fill($poll) !== null) {
                    $out['resumed']++;
                }

                continue;
            }

            if ($now->greaterThanOrEqualTo(SendPollBatchJob::deadline($launchedAt))) {
                $out['expired'] += $this->launcher->skipPending($poll->id, 'expired');
            } else {
                // One read of the poll's unsent rows along the partial index (an EXISTS on `pending` alone may be planned as a
                // sequential scan of the whole table when the planner expects many such rows).
                $stalled = DB::selectOne(
                    "SELECT count(*) FILTER (WHERE status = 'pending') AS pending, count(*) FILTER (WHERE claimed_at >= ?) AS live "
                    ."FROM poll_deliveries WHERE poll_id = ? AND status IN ('pending', 'sending')",
                    [$leaseEdge, $poll->id],
                );

                // No live lease: every chain is between batches (queued, paused after a 429 or "no slot", behind a long queue) or
                // dead. Only the slots with no queued job are started (the unique lock of SendPollBatchJob), so a waiting chain gets
                // no twin and a dead one comes back.
                if ($stalled !== null && (int) $stalled->pending > 0 && (int) $stalled->live === 0
                    && $this->launcher->startWorkers($poll->id, $launchedAt) > 0
                ) {
                    $out['restarted']++;
                }
            }

            if ($this->sender->finishIfDone($poll->id, $now)) {
                $out['finished']++;
            }
        }

        $stopped = Poll::query()
            ->whereIn('status', [PollStatus::Cancelled, PollStatus::Closed])
            ->where('updated_at', '>=', $now->subDay())
            ->pluck('id');

        foreach ($stopped as $pollId) {
            $out['skipped'] += $this->launcher->skipPending((int) $pollId, 'stopped');
        }

        return $out;
    }
}
