<?php

declare(strict_types=1);

namespace App\Domain\Game\Support;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The hardest words of a city for a learner (docs/18 §12.3.4) - the material of the boss, in ONE statement: the learner's
 * `word_repetitions` of the active words of the lessons of the module, with the mistakes the game counted (`game_word_misses`), the
 * worst first: `is_hard`, then `lapses`, then the misses of the game, then the lowest easiness factor, then the row (a stable tie-break).
 * Reads only; the caller fixes the answer in the snapshot of the run, so editing words or learning in the cards later changes nothing
 * in a fight that has started.
 *
 * {@see pickForGroup()} is the same rule for the boss of a class (G2.9a): the words of the course of the group the active members have been
 * introduced to, the hardest for the CLASS first - how many learners find a word hard, the lapses, the misses of the game summed over
 * the members, then the mean easiness - fixed in `class_caravans.boss_snapshot_words` at the start of the caravan.
 */
final class BossWordPicker
{
    /**
     * @return list<int> word ids, the hardest first
     */
    public function pick(int $learnerId, int $stageId, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = DB::table('word_repetitions')
            ->join('words', 'words.id', '=', 'word_repetitions.word_id')
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->leftJoin('game_word_misses', function (JoinClause $join) use ($learnerId): void {
                $join->on('game_word_misses.word_id', '=', 'words.id')->where('game_word_misses.learner_id', '=', $learnerId);
            })
            ->where('lessons.stage_id', $stageId)
            ->where('words.is_active', true)
            ->where('word_repetitions.learner_id', $learnerId)
            ->orderByDesc('word_repetitions.is_hard')
            ->orderByDesc('word_repetitions.lapses')
            ->orderByRaw('COALESCE(game_word_misses.misses, 0) DESC')
            ->orderBy('word_repetitions.easiness_factor')
            ->orderBy('word_repetitions.id')
            ->limit($limit)
            ->pluck('word_repetitions.word_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return $ids;
    }

    /**
     * @return list<int> word ids of the course, the hardest for the active members of the group first
     */
    public function pickForGroup(int $groupId, int $courseId, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = DB::table('word_repetitions as wr')
            ->join('words as w', function (JoinClause $join): void {
                $join->on('w.id', '=', 'wr.word_id')->where('w.is_active', '=', true);
            })
            ->join('lessons as l', 'l.id', '=', 'w.lesson_id')
            ->join('stages as s', function (JoinClause $join) use ($courseId): void {
                $join->on('s.id', '=', 'l.stage_id')->where('s.course_id', '=', $courseId);
            })
            ->join('group_members as gm', function (JoinClause $join) use ($groupId): void {
                $join->on('gm.learner_id', '=', 'wr.learner_id')->where('gm.telegram_group_id', '=', $groupId)->where('gm.status', '=', 'active');
            })
            ->leftJoin('game_word_misses as m', function (JoinClause $join): void {
                $join->on('m.learner_id', '=', 'wr.learner_id')->on('m.word_id', '=', 'wr.word_id');
            })
            ->groupBy('wr.word_id')
            ->selectRaw('wr.word_id, SUM(CASE WHEN wr.is_hard THEN 1 ELSE 0 END) AS hard, SUM(wr.lapses) AS lapses, COALESCE(SUM(m.misses), 0) AS misses, AVG(wr.easiness_factor) AS easiness')
            ->orderByDesc('hard')
            ->orderByDesc('lapses')
            ->orderByDesc('misses')
            ->orderBy('easiness')
            ->orderBy('wr.word_id')
            ->limit($limit)
            ->pluck('wr.word_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return $ids;
    }
}
