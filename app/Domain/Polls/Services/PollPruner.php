<?php

declare(strict_types=1);

namespace App\Domain\Polls\Services;

use App\Domain\Polls\Enums\PollStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `polls:prune` (docs/35 §1.5 "Приватность", O7), nightly: the answers are kept `polls.retention_days` (365) after a poll is closed
 * or stopped, then its votes and deliveries are deleted. The poll stays with its totals (`results_snapshot`, written at closing), so
 * the results page still shows them.
 *
 * In chunks of `polls.prune_chunk` rows: the ids of a chunk along `poll_votes (poll_id, poll_option_id)` /
 * `poll_deliveries (poll_id, learner_id)`, then one DELETE by the primary key - each statement its own short transaction (no long
 * transaction, no lock on the whole poll's rows, no huge WAL burst). The votes go first, so the cascade of the
 * deliveries finds nothing to do. The cached breakdowns of a pruned poll are forgotten. A poll already pruned costs two empty reads; a repeated or parallel run changes nothing twice.
 */
final class PollPruner
{
    public function __construct(private readonly PollReport $report) {}

    /** @return array{polls: int, votes: int, deliveries: int} */
    public function prune(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $edge = $now->subDays(max(1, (int) config('polls.retention_days', 365)));
        $chunk = max(100, (int) config('polls.prune_chunk', 5000));
        $out = ['polls' => 0, 'votes' => 0, 'deliveries' => 0];

        // Closed: from `closed_at`. Stopped: from the last write of the poll (the stop itself; a stopped poll is not edited any more
        // but its title). A small table: tens of rows a year.
        $polls = DB::table('polls')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', PollStatus::Closed->value)->where('closed_at', '<', $edge))
                ->orWhere(fn ($q) => $q->where('status', PollStatus::Cancelled->value)->where('updated_at', '<', $edge)))
            ->orderBy('id')
            ->pluck('id');

        foreach ($polls as $pollId) {
            $votes = $this->deleteInChunks('poll_votes', (int) $pollId, $chunk);
            $deliveries = $this->deleteInChunks('poll_deliveries', (int) $pollId, $chunk);

            if ($votes + $deliveries > 0) {
                // The cached breakdowns of the closed poll describe rows that are gone; its totals stay in `results_snapshot`.
                $this->report->forget((int) $pollId);
                $out['polls']++;
                $out['votes'] += $votes;
                $out['deliveries'] += $deliveries;
            }
        }

        return $out;
    }

    /**
     * A chunk = the ids of the poll's next rows (along the index that starts with `poll_id`) and one DELETE of them by the primary
     * key: two short statements. Not `DELETE … WHERE id IN (SELECT … LIMIT n)`: PostgreSQL plans that as a hash semi join over a
     * sequential scan of the whole table (of every poll) for each chunk.
     *
     * @param  'poll_votes'|'poll_deliveries'  $table
     */
    private function deleteInChunks(string $table, int $pollId, int $chunk): int
    {
        $total = 0;

        do {
            $ids = DB::table($table)->where('poll_id', $pollId)->limit($chunk)->pluck('id')->all();
            if ($ids === []) {
                break;
            }

            $total += DB::table($table)->whereIn('id', $ids)->delete();
        } while (count($ids) === $chunk);

        return $total;
    }
}
