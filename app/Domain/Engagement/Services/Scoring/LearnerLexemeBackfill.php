<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services\Scoring;

use App\Domain\Engagement\DTO\LearnerLexemeBackfillReport;
use App\Domain\Engagement\Enums\LexemeConfirmSource;
use App\Domain\Learning\Enums\RepetitionState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `learner_lexemes:backfill` (docs/32 §12.3, task S7): every lexeme a learner has already studied - a card of one of its words that
 * left the `new` state - becomes confirmed (`confirmed_source = legacy`), so the old words do not pay "new" (+2) once more after the
 * launch of the word points. Run it before `live` (§12.4 step 3).
 *
 *   - batches of learners by `learners.id` (keyset, soft-deleted included): one `INSERT … SELECT … ON CONFLICT` a batch, its own
 *     statement and transaction - no long transaction, nothing held between batches. The SELECT reads `word_repetitions` by the
 *     range of its unique `(learner_id, word_id)` and the words by their key;
 *   - two copies of one lexeme (two courses, a deck) are one row (GROUP BY);
 *   - the date is the rating day of the start of the run (§12.3): the true day of the first confirmation is not known (§0.7). A row
 *     the core created first keeps whatever it has: a confirmation of its own stays as it is (the conflict updates only rows with
 *     `confirmed_on IS NULL` - an attempt closed by a mistake before the backfill), so a second run changes nothing;
 *   - the rows are inserted in key order: the locks of a batch are taken in the order of `ScoreCorrectionService` (lexeme ascending
 *     within a learner); an answer locks one row of `learner_lexemes`, so no cycle with it either.
 *
 * The cards whose word has no lexeme (a deck word that matches nothing in the catalog) are counted and skipped.
 */
final class LearnerLexemeBackfill
{
    public function __construct(private readonly ScoreRules $rules) {}

    /** @param  (callable(int $learners, int $confirmed, int $lastLearnerId): void)|null  $progress  after every batch */
    public function run(int $batch = 200, ?int $learnerId = null, ?callable $progress = null, ?CarbonImmutable $now = null): LearnerLexemeBackfillReport
    {
        $report = new LearnerLexemeBackfillReport;
        $batch = max(1, $batch);
        $day = $this->rules->ratingDay($now ?? CarbonImmutable::now())->toDateString();
        $after = 0;

        do {
            $ids = DB::table('learners')
                ->when($learnerId !== null, fn ($q) => $q->where('id', $learnerId))
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit($batch)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($ids === []) {
                break;
            }

            $from = $ids[0];
            $to = $after = $ids[count($ids) - 1];

            $report->batches++;
            $report->learners += count($ids);
            $report->confirmed += $this->confirmRange($from, $to, $day);
            $report->withoutLexeme += $this->countWithoutLexeme($from, $to);

            if ($progress !== null) {
                $progress($report->learners, $report->confirmed, $to);
            }
        } while (count($ids) === $batch);

        return $report;
    }

    /** The rows written for the learners `$from..$to` (inserted, or a row of the core that gets its first confirmation). */
    private function confirmRange(int $from, int $to, string $day): int
    {
        return DB::affectingStatement(
            'INSERT INTO learner_lexemes (learner_id, lexeme_id, confirmed_on, confirmed_source) '
            .'SELECT wr.learner_id, w.lexeme_id, ?, ? '
            .'FROM word_repetitions wr JOIN words w ON w.id = wr.word_id '
            .'WHERE wr.learner_id BETWEEN ? AND ? AND wr.state <> ? AND w.lexeme_id IS NOT NULL '
            .'GROUP BY wr.learner_id, w.lexeme_id '
            .'ORDER BY wr.learner_id, w.lexeme_id '
            .'ON CONFLICT (learner_id, lexeme_id) DO UPDATE SET '
            .'confirmed_on = COALESCE(learner_lexemes.confirmed_on, EXCLUDED.confirmed_on), '
            .'confirmed_source = COALESCE(learner_lexemes.confirmed_source, EXCLUDED.confirmed_source) '
            .'WHERE learner_lexemes.confirmed_on IS NULL',
            [$day, LexemeConfirmSource::Legacy->value, $from, $to, RepetitionState::New->value],
        );
    }

    /** The studied cards of the learners `$from..$to` whose word has no lexeme. */
    private function countWithoutLexeme(int $from, int $to): int
    {
        return DB::table('word_repetitions as wr')
            ->join('words as w', 'w.id', '=', 'wr.word_id')
            ->whereBetween('wr.learner_id', [$from, $to])
            ->where('wr.state', '<>', RepetitionState::New->value)
            ->whereNull('w.lexeme_id')
            ->count();
    }
}
