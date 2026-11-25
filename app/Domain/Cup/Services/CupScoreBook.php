<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Cup\DTO\BestResult;
use App\Domain\Cup\DTO\DayScore;
use App\Domain\Cup\Support\CupScoring;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of the points of a cup (docs/33 §5.3, §5.5): `cup_day_scores` and the `best_*` of the participant. Every method that
 * writes a participant's scores runs under the lock of his row (`cup_participants … FOR UPDATE`), which the caller takes - in the
 * order `cups → cup_round_attempts → cup_participants → cup_day_scores` that every path of the cup keeps.
 *
 * The points of the day are added by ONE upsert clamped to the cap of the round in SQL (a retried or a racing writer never passes 20),
 * then the participant's best days are read back (≤ `days` rows) and written.
 */
final class CupScoreBook
{
    /** The day was played: its row exists (with 0 points) and counts in `days_played`. Under the lock of the participant. */
    public function openDay(int $cupId, int $participantId, int $day, CarbonImmutable $now): void
    {
        $inserted = DB::table('cup_day_scores')->insertOrIgnore([
            'cup_id' => $cupId,
            'participant_id' => $participantId,
            'day' => $day,
            'round_points' => 0,
            'duel_points' => 0,
            'updated_at' => $now,
        ]);
        if ($inserted > 0) {
            DB::table('cup_participants')->where('id', $participantId)->increment('days_played', 1, ['updated_at' => $now]);
        }
    }

    /** Adds `$points` of a round to the day and recounts the best days. Under the lock of the participant. */
    public function addRoundPoints(int $cupId, int $participantId, int $day, int $points, int $countedDays, CarbonImmutable $now): BestResult
    {
        $cap = CupScoring::ROUND_CAP;
        DB::statement(
            'INSERT INTO cup_day_scores (cup_id, participant_id, day, round_points, duel_points, updated_at) VALUES (?, ?, ?, ?, 0, ?)
             ON CONFLICT (participant_id, day) DO UPDATE SET
                round_points = CASE WHEN cup_day_scores.round_points + excluded.round_points > ? THEN ? ELSE cup_day_scores.round_points + excluded.round_points END,
                updated_at = excluded.updated_at',
            [$cupId, $participantId, $day, min($points, $cap), $now->format('Y-m-d H:i:s'), $cap, $cap],
        );

        return $this->recount($participantId, $countedDays, $now);
    }

    /** Reads the days of the participant (≤ the days of the cup) and writes his best result. Under the lock of the participant. */
    public function recount(int $participantId, int $countedDays, CarbonImmutable $now): BestResult
    {
        $days = [];
        foreach (DB::table('cup_day_scores')->where('participant_id', $participantId)->get(['day', 'round_points', 'duel_points']) as $row) {
            $days[] = new DayScore((int) $row->day, (int) $row->round_points, (int) $row->duel_points);
        }
        $best = CupScoring::best($days, $countedDays);

        DB::table('cup_participants')->where('id', $participantId)->update([
            'best_total' => $best->total,
            'best_round_points' => $best->roundPoints,
            'updated_at' => $now,
        ]);

        return $best;
    }

    /**
     * The places of the finish (docs/33 §5.6), one statement: `RANK()` by (best_total, best_round_points) - a full tie shares the place
     * and the next is skipped. The caller holds the locks of the cup and of every participant.
     */
    public function writeFinalRanks(int $cupId): int
    {
        return DB::update(
            'UPDATE cup_participants SET final_rank = ranked.place
             FROM (SELECT id, RANK() OVER (ORDER BY best_total DESC, best_round_points DESC) AS place FROM cup_participants WHERE cup_id = ?) AS ranked
             WHERE cup_participants.id = ranked.id',
            [$cupId],
        );
    }
}
