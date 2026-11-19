<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Learning\DTO\CorrectedMistake;
use App\Domain\Learning\Enums\ExerciseType;
use App\Models\TrainingSession;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Earlier you got «X» wrong - today you answered it right" on the finish of a round of hard words (docs/06 §14, audit 2026-11-18 §3):
 * one trustworthy pair "was wrong before → answered right now", or none.
 *
 * Trustworthy means objective on both sides. NOW: the word's FIRST grade in this session is a pass (quality >= 3) on an exercise the
 * server graded - not `flash` (the learner's own opinion) and not `pretest` (a first exposure); a fail in the round followed by a pass is
 * not a correction, it is the relearning of the round. BEFORE: a fail (quality < 3) on an objective exercise in an EARLIER session - a
 * review whose id is below the first review of this session (the ids grow with time); a fail earlier in this very session never counts.
 * Of the candidates the most recent earlier mistake wins.
 *
 * Cost: ONE statement (`training_reviews` of the session by `training_reviews_session_learner_idx`, the earlier grades of each of its
 * ≤ 10 words by `training_reviews_learner_word_idx (learner_id, word_id, id)`, the word by its key), and only for the finish of a round of
 * hard words. The translation comes from {@see WordTranslationResolver} - warm: the cards of the round were served in the same locale.
 */
final class CorrectedMistakeFinder
{
    public function __construct(
        private readonly WordTranslationResolver $translations,
        private readonly SpellingGrader $grader,
    ) {}

    public function find(TrainingSession $session, string $locale): ?CorrectedMistake
    {
        $learnerId = $session->learner_id;
        if ($learnerId === null) {
            return null;
        }

        $subjective = self::subjectiveExercises();

        // The grades of the session: the n-th grade of each word and the first review of the whole session.
        $grades = DB::table('training_reviews')
            ->where('training_session_id', $session->id)
            ->where('learner_id', $learnerId)
            ->select(['word_id', 'quality', 'exercise'])
            ->selectRaw('row_number() over (partition by "word_id" order by "id") as n')
            ->selectRaw('min("id") over () as session_first_id');

        /** @var object{id: int|string, word: string, translation: string|null, exercise: string, answer_text: string|null, created_at: string}|null $row */
        $row = DB::query()
            ->fromSub($grades, 'f')
            ->join('training_reviews as m', function ($join) use ($learnerId): void {
                $join->on('m.word_id', '=', 'f.word_id')
                    ->on('m.id', '<', 'f.session_first_id')
                    ->where('m.learner_id', '=', $learnerId);
            })
            ->join('words as w', 'w.id', '=', 'f.word_id')
            ->where('f.n', 1)
            ->where('f.quality', '>=', SpacedRepetitionEngine::PASS_QUALITY)
            ->whereNotIn('f.exercise', $subjective)
            ->where('m.quality', '<', SpacedRepetitionEngine::PASS_QUALITY)
            ->whereNotIn('m.exercise', $subjective)
            ->orderByDesc('m.id')
            ->limit(1)
            ->first(['w.id', 'w.word', 'w.translation', 'm.exercise', 'm.answer_text', 'm.created_at']);

        if ($row === null) {
            return null;
        }

        $word = (new Word)->newFromBuilder(['id' => (int) $row->id, 'word' => $row->word, 'translation' => $row->translation]);

        return new CorrectedMistake(
            wordId: (int) $row->id,
            word: $row->word,
            translation: $this->translations->for($word, $locale),
            mistakenAt: CarbonImmutable::parse($row->created_at, 'UTC'),
            answer: $this->shownAnswer($row->exercise, $row->answer_text, $row->word),
        );
    }

    /**
     * The earlier answer, when the learner TYPED it (spelling, gap-fill: a picked option of a four-option card is ours, not theirs) and it
     * is safe to show: printable, not empty, and not the right word itself (a gap-fill expects a form; its base form is not shown as wrong).
     */
    private function shownAnswer(string $exercise, ?string $answer, string $word): ?string
    {
        if ($answer === null || ExerciseType::tryFrom($exercise)?->hidesWord() !== true) {
            return null;
        }

        $clean = trim((string) preg_replace('/[\p{C}]+/u', '', $answer));
        if ($clean === '' || $this->grader->normalize($clean) === $this->grader->normalize($word)) {
            return null;
        }

        return $clean;
    }

    /**
     * The exercises whose grade is no evidence of a mistake nor of its correction: the learner's own grade and the first exposure.
     *
     * @return list<string>
     */
    private static function subjectiveExercises(): array
    {
        $out = [];
        foreach (ExerciseType::cases() as $type) {
            if ($type->selfGraded() || $type === ExerciseType::Pretest) {
                $out[] = $type->value;
            }
        }

        return $out;
    }
}
