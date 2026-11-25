<?php

declare(strict_types=1);

namespace App\Domain\Polls\Services;

use App\Domain\Polls\Enums\PollStatus;
use App\Models\Poll;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * `polls:prune` (docs/35 §1.5 "Приватность", O7), nightly: the answers are kept `polls.retention_days` (365) after a poll is closed
 * or stopped, then its votes and deliveries are deleted. The poll stays with its totals (`results_snapshot`, written at closing), so
 * the results page still shows them.
 *
 * In chunks of `polls.prune_chunk` rows: the ids of a chunk along `poll_votes (poll_id, poll_option_id)` /
 * `poll_deliveries (poll_id, learner_id)`, then one DELETE by the primary key - each statement its own short transaction (no long
 * transaction, no lock on the whole poll's rows, no huge WAL burst). The votes go first, so the cascade of the
 * deliveries finds nothing to do. The cached breakdowns of a pruned poll are forgotten. A poll already pruned costs two empty reads; a repeated or parallel run changes nothing twice.
 *
 * The same command removes the orphaned pictures of `polls/` ({@see pruneOrphanImages()}).
 */
final class PollPruner
{
    /** A file of `polls/` no poll refers to is deleted once it is this old: a form being filled keeps its upload. */
    public const ORPHAN_AGE_HOURS = 24;

    private const ORPHAN_CHUNK = 500;

    public function __construct(private readonly PollReport $report) {}

    /** @return array{polls: int, votes: int, deliveries: int} */
    public function prune(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $edge = $now->subDays(max(1, (int) config('polls.retention_days', 365)));
        $chunk = max(100, (int) config('polls.prune_chunk', 5000));
        $out = ['polls' => 0, 'votes' => 0, 'deliveries' => 0];

        // Closed: from `closed_at`. Stopped: from the stop - the time of its audit row `poll.cancelled` (the poll has no column of its
        // own for it; `updated_at` moves with every rename of the title, which would put the delete off), `updated_at` only for a poll
        // without that row. A small table: tens of rows a year; the audit rows by `(entity_type, entity_id)`.
        $edgeAt = $edge->format('Y-m-d H:i:s');
        $polls = DB::table('polls')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', PollStatus::Closed->value)->where('closed_at', '<', $edgeAt))
                ->orWhere(fn ($q) => $q->where('status', PollStatus::Cancelled->value)->whereRaw(
                    'coalesce((select max(a.created_at) from audit_logs a where a.entity_type = ? and a.entity_id = polls.id and a.action = ?), polls.updated_at) < ?',
                    [Poll::class, PollLauncher::AUDIT_CANCELLED, $edgeAt],
                )))
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
     * The pictures of `polls/` on the private disk that no poll refers to and that are older than {@see ORPHAN_AGE_HOURS}: a form that
     * stored its upload and then was refused or abandoned, a replaced picture whose delete failed. The age keeps a form that is being
     * filled right now safe. One read of the referenced paths per {@see ORPHAN_CHUNK} files. Returns the files deleted.
     */
    public function pruneOrphanImages(?CarbonImmutable $now = null): int
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $disk = Storage::disk(PollEditor::IMAGE_DISK);
        $edge = $now->subHours(self::ORPHAN_AGE_HOURS)->getTimestamp();
        $deleted = 0;

        $old = array_values(array_filter(
            $disk->files(PollEditor::IMAGE_DIRECTORY),
            static fn (string $path): bool => $disk->lastModified($path) < $edge,
        ));

        foreach (array_chunk($old, self::ORPHAN_CHUNK) as $paths) {
            $used = DB::table('polls')->whereIn('image_path', $paths)->pluck('image_path')->all();
            foreach (array_diff($paths, $used) as $path) {
                if (PollEditor::deleteImageIfUnused($path)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
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
