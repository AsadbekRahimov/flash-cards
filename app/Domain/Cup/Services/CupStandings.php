<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Cup\Enums\CupParticipantStatus;
use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Support\CupScoring;
use App\Domain\Operations\Support\PureCache;
use App\Models\Cup;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The table of a cup (docs/33 §2.3, §7.2 `standings`): one indexed query - the participants of the cup through the unique
 * `(cup_id, learner_id)` - sorted in memory (tens of rows; no index on the scores, so their updates stay HOT), ranked by
 * {@see CupScoring::ranks()}; a finished cup shows `final_rank` as the finish wrote it.
 *
 * Pure cache (docs/19 §3): `cup:standings:v1:{cup id}` - a list of arrays, TTL `cup.standings_ttl` (120 s), dropped after the commit
 * of every write that changes it (points, a join, a name, leaving and returning, the finish). Shown, never decided by: no service
 * reads it to grant anything.
 */
final class CupStandings
{
    private const PREFIX = 'cup:standings:v1:';

    /**
     * @return list<array{participant_id: int, rank: int, display_name: string, total: int, round_points: int, days_played: int, left: bool}>
     */
    public function table(Cup $cup): array
    {
        $key = self::key($cup->id);
        try {
            $cached = PureCache::store()->get($key);
            if (is_array($cached)) {
                /** @var list<array{participant_id: int, rank: int, display_name: string, total: int, round_points: int, days_played: int, left: bool}> $cached */
                return $cached;
            }
        } catch (Throwable $e) {
            report($e);
        }

        $table = $this->read($cup);

        try {
            PureCache::store()->put($key, $table, max(1, (int) config('cup.standings_ttl', 120)));
        } catch (Throwable $e) {
            report($e);
        }

        return $table;
    }

    /** Drops the cached table now (the caller has committed). Never throws: a failed drop lives out its TTL (docs/19 §3 rule 5). */
    public function forget(int $cupId): void
    {
        PureCache::forgetMany([self::key($cupId)]);
    }

    /** Drops the cached table after the commit of the current transaction (at once outside one). */
    public function forgetAfterCommit(int $cupId): void
    {
        DB::afterCommit(fn () => $this->forget($cupId));
    }

    public static function key(int $cupId): string
    {
        return self::PREFIX.$cupId;
    }

    /**
     * @return list<array{participant_id: int, rank: int, display_name: string, total: int, round_points: int, days_played: int, left: bool}>
     */
    private function read(Cup $cup): array
    {
        $rows = DB::table('cup_participants')
            ->where('cup_id', $cup->id)
            ->get(['id', 'display_name', 'status', 'best_total', 'best_round_points', 'days_played', 'final_rank']);

        $scores = [];
        foreach ($rows as $row) {
            $scores[(int) $row->id] = ['total' => (int) $row->best_total, 'round' => (int) $row->best_round_points];
        }
        $ranks = CupScoring::ranks($scores);
        $finished = $cup->status === CupStatus::Finished;

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row->id] = $row;
        }

        $table = [];
        foreach ($ranks as $id => $rank) {
            $row = $byId[$id];
            $table[] = [
                'participant_id' => $id,
                'rank' => $finished && $row->final_rank !== null ? (int) $row->final_rank : $rank,
                'display_name' => (string) $row->display_name,
                'total' => (int) $row->best_total,
                'round_points' => (int) $row->best_round_points,
                'days_played' => (int) $row->days_played,
                'left' => $row->status === CupParticipantStatus::Left->value,
            ];
        }

        return $table;
    }
}
