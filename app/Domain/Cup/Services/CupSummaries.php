<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Cup\Enums\CupMessageKind;
use App\Domain\Cup\Enums\CupMessageStatus;
use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Support\CupAccess;
use App\Jobs\PostCupMessageJob;
use App\Models\Cup;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `cups:post-summaries` (docs/33 §3.2, §6.1): the summary of the day of a running cup at its `summary_minute`, at most one a day and
 * only with new results: `sha1` of the points of the table (the participants with points - a join alone or a day played without a
 * point changes nothing) differs from the one of the last summary (`cups.summary_scores_hash`); a table without a single point is no
 * summary at all. Otherwise the day is marked decided (`cup_days.summary_posted_at`) without a message. Summaries switched off
 * (`summaries_enabled = false`) are not even looked at; a paused cup or a group without access marks its day decided.
 *
 * The decision is idempotent: ONE `UPDATE cup_days … WHERE summary_posted_at IS NULL RETURNING id` takes the days of the batch, and
 * only the days THIS statement took get a row - of two schedulers deciding the same day, the second gets nothing back. The rows are
 * `INSERT … ON CONFLICT (cup_id, dedupe_key) DO NOTHING RETURNING id` (unique per cup and day), the jobs go after the commit. The
 * message itself goes through the outbox ({@see CupOutbox}).
 *
 * The time of the summary is in the SQL (`day start + summary_minute` ≤ now; `cup_days.starts_at` is the local midnight of the cup
 * in UTC, so the minute of the day is in the cup's own time zone): a batch never fills with cups whose time has not come while a due
 * one waits behind them (they used to be cut by `LIMIT` before the check).
 *
 * Statements, whatever the number of cups: 1 for the candidates (the running cups through their partial index, today's row of each,
 * its group); with due cups + 1 the tables (the ones not cached), + 1 the decision of the days, + 1 the hashes of the cups to post
 * (`UPDATE … CASE id`), + 1 the rows of the outbox.
 */
final class CupSummaries
{
    public function __construct(
        private readonly CupStandings $standings,
    ) {}

    /** @return array{due: int, queued: int, quiet: int} */
    public function enqueueDue(CarbonImmutable $now, int $batch): array
    {
        $report = ['due' => 0, 'queued' => 0, 'quiet' => 0];

        $query = DB::table('cups as c')
            ->join('cup_days as d', 'd.cup_id', '=', 'c.id')
            ->join('telegram_groups as g', 'g.id', '=', 'c.telegram_group_id')
            ->where('c.status', CupStatus::Running->value)
            ->where('c.ends_at', '>', $now)
            ->where('c.summaries_enabled', true)
            ->where('d.starts_at', '<=', $now)
            ->where('d.ends_at', '>', $now)
            ->whereNull('d.summary_posted_at');
        DB::getDriverName() === 'pgsql'
            ? $query->whereRaw("d.starts_at + c.summary_minute * interval '1 minute' <= ?", [$now->format('Y-m-d H:i:s')])
            : $query->whereRaw("datetime(d.starts_at, '+' || c.summary_minute || ' minutes') <= ?", [$now->format('Y-m-d H:i:s')]);
        $due = $query->orderBy('c.id')
            ->limit(max(1, $batch))
            ->get(['c.id', 'c.summary_scores_hash', 'd.id as day_id', 'd.day', 'g.bot_status', 'g.meta']);
        if ($due->isEmpty()) {
            return $report;
        }
        $report['due'] = $due->count();

        $open = [];
        foreach ($due as $row) {
            $group = (new TelegramGroup)->forceFill([
                'bot_status' => $row->bot_status,
                'meta' => is_string($row->meta) ? json_decode($row->meta, true) : [],
            ]);
            if (CupAccess::allowsGroup($group) && CupAccess::pauseReason($group) === null) {
                $open[] = (new Cup)->forceFill(['id' => (int) $row->id, 'status' => CupStatus::Running]);
            }
        }
        $tables = $this->standings->tables($open);

        /** @var array<int, array{cup: int, day: int, hash: string}> $post day id => what to post */
        $post = [];
        foreach ($due as $row) {
            $hash = isset($tables[(int) $row->id]) ? self::hashOf($tables[(int) $row->id]) : null;
            if ($hash !== null && $hash !== $row->summary_scores_hash) {
                $post[(int) $row->day_id] = ['cup' => (int) $row->id, 'day' => (int) $row->day, 'hash' => $hash];
            }
        }

        $queued = DB::transaction(function () use ($due, $post, $now): int {
            $dayIds = $due->pluck('day_id')->map(static fn (mixed $id): int => (int) $id)->all();
            $taken = array_map(static fn (object $row): int => (int) $row->id, DB::select(
                'UPDATE cup_days SET summary_posted_at = ?, updated_at = ? WHERE id IN ('.self::marks(count($dayIds)).') AND summary_posted_at IS NULL RETURNING id',
                [$now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), ...$dayIds],
            ));
            $mine = array_intersect_key($post, array_flip($taken));
            if ($mine === []) {
                return 0;
            }

            $case = [];
            $values = [];
            $stamp = $now->format('Y-m-d H:i:s');
            foreach ($mine as $item) {
                array_push($case, $item['cup'], $item['hash']);
                array_push($values, $item['cup'], CupOutbox::summaryKey($item['day']), CupMessageKind::Summary->value, CupMessageStatus::Pending->value, $stamp, $stamp);
            }
            $cupIds = array_column($mine, 'cup');
            DB::update(
                'UPDATE cups SET summary_scores_hash = CASE id'.str_repeat(' WHEN ? THEN ?', count($mine)).' ELSE summary_scores_hash END WHERE id IN ('.self::marks(count($cupIds)).')',
                [...$case, ...$cupIds],
            );
            $rows = DB::select(
                'INSERT INTO cup_messages (cup_id, dedupe_key, kind, status, attempts, created_at, updated_at) VALUES '
                .implode(', ', array_fill(0, count($mine), '(?, ?, ?, ?, 0, ?, ?)'))
                .' ON CONFLICT (cup_id, dedupe_key) DO NOTHING RETURNING id',
                $values,
            );
            foreach ($rows as $row) {
                PostCupMessageJob::dispatch((int) $row->id)->afterCommit();
            }

            return count($rows);
        });

        $report['queued'] = $queued;
        $report['quiet'] = $report['due'] - $queued;

        return $report;
    }

    /**
     * sha1 of the points of the table of a running cup - (participant, total, round points) of those with points: "new results" since
     * the last summary. Null when nobody has a point yet. The table is the cached one of the Mini App ({@see CupStandings}), read by
     * the id and the status of the cup only.
     */
    public function scoresHash(int $cupId): ?string
    {
        return self::hashOf($this->standings->table((new Cup)->forceFill(['id' => $cupId, 'status' => CupStatus::Running])));
    }

    /** @param list<array{participant_id: int, rank: int, display_name: string, total: int, round_points: int, days_played: int, left: bool}> $table */
    private static function hashOf(array $table): ?string
    {
        $rows = [];
        foreach ($table as $row) {
            if ($row['total'] > 0) {
                $rows[] = [$row['participant_id'], $row['total'], $row['round_points']];
            }
        }

        return $rows === [] ? null : sha1(json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private static function marks(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }
}
