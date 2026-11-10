<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Support\LadderPolicy;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Support\Facades\DB;

/**
 * What a "hard word" (leech, docs/25 §3.3, docs/06 §12.6) is shown with besides the hook and another example: the word
 * it is confused with, side by side.
 *
 * Two sources, the content first: `words.meta.confusable_with` (the first mark), else the learner's own wrong picks — the
 * last wrong `reverse` answers of this (learner, word) in `training_reviews.answer_text` (the picked English word; the
 * four-option cards write it since docs/06 §12.2), the most frequent of the last few. The translation of the pair comes
 * from the cached distractor pool of the lesson (no statement) when the pair is a word of the same lesson, which is where
 * the distractors come from first; otherwise the pair is shown without one.
 *
 * Cost: ONE statement, and only for a leech (`lapses >= learning.leech.threshold`) — the usual card never gets here.
 */
final class LeechInsight
{
    private const RECENT_WRONG_PICKS = 5;

    public function __construct(
        private readonly LearningCache $cache,
        private readonly LadderPolicy $policy,
    ) {}

    /**
     * @return array{word: string, translation: string|null}|null
     */
    public function confusedWith(WordRepetition $repetition, Word $word): ?array
    {
        if (! $this->policy->isLeech($repetition)) {
            return null;
        }

        $pool = [];
        foreach ($this->cache->lessonDistractorPool((int) $word->lesson_id) as $row) {
            $pool[mb_strtolower(trim($row['word']))] = $row;
        }

        foreach ($word->confusableWith() as $marked) {
            if ($marked !== mb_strtolower(trim($word->word))) {
                return ['word' => $pool[$marked]['word'] ?? $marked, 'translation' => $pool[$marked]['translation'] ?? null];
            }
        }

        /** @var list<string> $picks */
        $picks = DB::table('training_reviews')
            ->where('learner_id', $repetition->learner_id)
            ->where('word_id', $word->id)
            ->where('exercise', ExerciseType::Reverse->value)
            ->where('quality', '<', SpacedRepetitionEngine::PASS_QUALITY)
            ->whereNotNull('answer_text')
            ->where('answer_text', '<>', '')
            ->orderByDesc('id')
            ->limit(self::RECENT_WRONG_PICKS)
            ->pluck('answer_text')
            ->map(static fn (mixed $text): string => mb_strtolower(trim((string) $text)))
            ->all();

        $counts = [];
        foreach ($picks as $pick) {
            if ($pick === '' || $pick === mb_strtolower(trim($word->word))) {
                continue;
            }
            $counts[$pick] = ($counts[$pick] ?? 0) + 1;
        }
        if ($counts === []) {
            return null;
        }

        // The most frequent; ties go to the most recent (the first seen in the list).
        $best = null;
        foreach ($counts as $pick => $count) {
            if ($best === null || $count > $counts[$best]) {
                $best = $pick;
            }
        }

        /** @var string $best */
        return ['word' => $pool[$best]['word'] ?? $best, 'translation' => $pool[$best]['translation'] ?? null];
    }
}
