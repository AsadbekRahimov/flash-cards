<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Cup\Enums\CupMessageKind;
use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Support\CupAccess;
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
 * The decision is idempotent: the row `summary:N` is unique per cup, the day marker is a conditional UPDATE, so a second scheduler or a
 * repeated run posts nothing twice. The message itself goes through the outbox ({@see CupOutbox}).
 *
 * Statements: 1 for the candidates (the running cups through their partial index, today's row of each, its group), whatever their
 * number; then per due cup the table (1 cold, 0 warm) and 1-4 writes.
 */
final class CupSummaries
{
    public function __construct(
        private readonly CupStandings $standings,
        private readonly CupOutbox $outbox,
    ) {}

    /** @return array{due: int, queued: int, quiet: int} */
    public function enqueueDue(CarbonImmutable $now, int $batch): array
    {
        $report = ['due' => 0, 'queued' => 0, 'quiet' => 0];

        $candidates = DB::table('cups as c')
            ->join('cup_days as d', 'd.cup_id', '=', 'c.id')
            ->join('telegram_groups as g', 'g.id', '=', 'c.telegram_group_id')
            ->where('c.status', CupStatus::Running->value)
            ->where('c.ends_at', '>', $now)
            ->where('c.summaries_enabled', true)
            ->where('d.starts_at', '<=', $now)
            ->where('d.ends_at', '>', $now)
            ->whereNull('d.summary_posted_at')
            ->orderBy('c.id')
            ->limit(max(1, $batch))
            ->get(['c.id', 'c.summary_minute', 'c.summary_scores_hash', 'd.id as day_id', 'd.day', 'd.starts_at', 'g.bot_status', 'g.meta']);

        $due = [];
        foreach ($candidates as $row) {
            $at = CarbonImmutable::parse((string) $row->starts_at, 'UTC')->addMinutes((int) $row->summary_minute);
            if ($at->lte($now)) {
                $due[(int) $row->id] = $row;
            }
        }
        if ($due === []) {
            return $report;
        }
        $report['due'] = count($due);

        foreach ($due as $cupId => $row) {
            $day = (int) $row->day;
            $group = (new TelegramGroup)->forceFill([
                'bot_status' => $row->bot_status,
                'meta' => is_string($row->meta) ? json_decode($row->meta, true) : [],
            ]);
            if (! CupAccess::allowsGroup($group) || CupAccess::pauseReason($group) !== null) {
                $this->decide((int) $row->day_id, $now);
                $report['quiet']++;

                continue;
            }

            $hash = $this->scoresHash($cupId);
            if ($hash === null || $hash === $row->summary_scores_hash) {
                $this->decide((int) $row->day_id, $now);
                $report['quiet']++;

                continue;
            }

            // The day marker first: of two schedulers deciding the same day, only the one whose UPDATE took it queues the row.
            $queued = DB::transaction(function () use ($cupId, $day, $row, $hash, $now): bool {
                if (! $this->decide((int) $row->day_id, $now)) {
                    return false;
                }
                DB::table('cups')->where('id', $cupId)->update(['summary_scores_hash' => $hash]);

                return $this->outbox->enqueue($cupId, CupOutbox::summaryKey($day), CupMessageKind::Summary, $now);
            });
            $report[$queued ? 'queued' : 'quiet']++;
        }

        return $report;
    }

    /**
     * sha1 of the points of the table of a running cup - (participant, total, round points) of those with points: "new results" since
     * the last summary. Null when nobody has a point yet. The table is the cached one of the Mini App ({@see CupStandings}), read by
     * the id and the status of the cup only.
     */
    public function scoresHash(int $cupId): ?string
    {
        $rows = [];
        foreach ($this->standings->table((new Cup)->forceFill(['id' => $cupId, 'status' => CupStatus::Running])) as $row) {
            if ($row['total'] > 0) {
                $rows[] = [$row['participant_id'], $row['total'], $row['round_points']];
            }
        }

        return $rows === [] ? null : sha1(json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /** The summary of the day is decided (posted or not); true when this call decided it. */
    private function decide(int $cupDayId, CarbonImmutable $now): bool
    {
        return DB::table('cup_days')->where('id', $cupDayId)->whereNull('summary_posted_at')->update(['summary_posted_at' => $now, 'updated_at' => $now]) === 1;
    }
}
