<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Models\ExamSession;
use Illuminate\Support\Facades\DB;

/**
 * Read side of a closed group exam for the teacher (the `/org` cabinet): who scored what, who did not take part, and which words the
 * class got wrong. Aggregates only - one GROUP BY / join per call, never a query per learner or per word. The ranking itself is
 * {@see LeaderboardBuilder}'s: this reads its `exam_results`.
 */
final class ExamReportService
{
    /**
     * Results of the exam by place (ties in the order of the stored rank, then of the id).
     *
     * @return list<array{learner_id: int|null, name: string, correct: int, total: int, percent: float, score: int, rank: int}>
     */
    public function results(ExamSession $exam): array
    {
        $rows = DB::table('exam_results as r')
            ->leftJoin('learners as l', 'l.id', '=', 'r.learner_id')
            ->where('r.exam_session_id', $exam->id)
            ->orderBy('r.rank')
            ->orderBy('r.id')
            ->get(['r.learner_id', 'l.first_name', 'l.last_name', 'l.deleted_at', 'r.correct_count', 'r.total_count', 'r.total_score', 'r.rank']);

        $out = [];
        foreach ($rows as $row) {
            $correct = (int) $row->correct_count;
            $total = (int) $row->total_count;
            $out[] = [
                'learner_id' => $row->learner_id === null ? null : (int) $row->learner_id,
                'name' => $this->name($row->first_name, $row->last_name, $row->deleted_at),
                'correct' => $correct,
                'total' => $total,
                'percent' => self::percent($correct, $total),
                'score' => (int) $row->total_score,
                'rank' => (int) $row->rank,
            ];
        }

        return $out;
    }

    /**
     * Active members of the group who have no result in the exam, by name. The roster is the CURRENT one (the group keeps no
     * snapshot of who was in it at the start of the exam).
     *
     * @return list<string>
     */
    public function absent(ExamSession $exam): array
    {
        if ($exam->telegram_group_id === null) {
            return [];
        }

        $rows = DB::table('group_members as gm')
            ->join('learners as l', 'l.id', '=', 'gm.learner_id')
            ->where('gm.telegram_group_id', $exam->telegram_group_id)
            ->where('gm.status', 'active')
            ->whereNull('l.deleted_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('exam_results as r')
                ->whereColumn('r.learner_id', 'gm.learner_id')
                ->where('r.exam_session_id', $exam->id))
            ->orderBy('l.first_name')
            ->orderBy('l.id')
            ->get(['l.first_name', 'l.last_name', 'l.deleted_at']);

        $names = [];
        foreach ($rows as $row) {
            $names[] = $this->name($row->first_name, $row->last_name, $row->deleted_at);
        }

        return $names;
    }

    /**
     * Words of the exam with the share of right answers of the whole class, the hardest first (lowest share; then by the word).
     *
     * @return list<array{word_id: int, word: string, translation: string, correct: int, total: int, percent: float}>
     */
    public function words(ExamSession $exam): array
    {
        $rows = DB::table('exam_answers as a')
            ->join('words as w', 'w.id', '=', 'a.word_id')
            ->where('a.exam_session_id', $exam->id)
            ->groupBy('a.word_id', 'w.word', 'w.translation')
            ->selectRaw('a.word_id, w.word, w.translation, SUM(CASE WHEN a.is_correct THEN 1 ELSE 0 END) AS correct_count, COUNT(*) AS total_count')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $correct = (int) $row->correct_count;
            $total = (int) $row->total_count;
            $out[] = [
                'word_id' => (int) $row->word_id,
                'word' => (string) $row->word,
                'translation' => (string) $row->translation,
                'correct' => $correct,
                'total' => $total,
                'percent' => self::percent($correct, $total),
            ];
        }

        usort($out, fn (array $a, array $b): int => [$a['percent'], $a['word'], $a['word_id']] <=> [$b['percent'], $b['word'], $b['word_id']]);

        return $out;
    }

    public static function percent(int $correct, int $total): float
    {
        return $total > 0 ? round($correct * 100 / $total, 1) : 0.0;
    }

    /** A deleted account (soft delete, until the purge) keeps its result in the totals but is not named. */
    private function name(mixed $first, mixed $last, mixed $deletedAt): string
    {
        if ($deletedAt !== null) {
            return '—';
        }

        $name = trim((is_string($first) ? $first : '').' '.(is_string($last) ? $last : ''));

        return $name === '' ? '—' : $name;
    }
}
