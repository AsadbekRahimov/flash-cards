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
}
