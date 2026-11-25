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
 * In chunks of `polls.prune_chunk` rows: each `DELETE … WHERE id IN (SELECT id … WHERE poll_id = ? LIMIT n)` is its own statement
 * and its own short transaction (no long transaction, no lock on the whole poll's rows, no huge WAL burst), along
 * `poll_votes (poll_id, poll_option_id)` and `poll_deliveries (poll_id, learner_id)`. The votes go first, so the cascade of the
 * deliveries finds nothing to do. A poll already pruned costs two empty reads; a repeated or parallel run changes nothing twice.
 */
final class PollPruner
{
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
                $out['polls']++;
                $out['votes'] += $votes;
                $out['deliveries'] += $deliveries;
            }
        }

        return $out;
    }

    /** @param 'poll_votes'|'poll_deliveries' $table */
    private function deleteInChunks(string $table, int $pollId, int $chunk): int
    {
        $total = 0;

        do {
            $deleted = DB::delete(
                "DELETE FROM {$table} WHERE id IN (SELECT id FROM {$table} WHERE poll_id = ? LIMIT ?)",
                [$pollId, $chunk],
            );
            $total += $deleted;
        } while ($deleted === $chunk);

        return $total;
    }
}
