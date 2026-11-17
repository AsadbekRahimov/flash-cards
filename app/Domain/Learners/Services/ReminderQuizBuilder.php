<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Learners\DTO\ReminderContent;
use App\Domain\Learners\DTO\ReminderQuiz;
use App\Domain\Learners\Enums\ReminderKind;
use App\Domain\Learning\DTO\LearnerExerciseSettings;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Services\ExercisePromptBuilder;
use App\Domain\Learning\Services\ExerciseSelector;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Models\Learner;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Picks the question of a reminder (docs/12 §7.5 "вопрос в напоминании", docs/06 §6): one of the learner's own words with
 * four options, or nothing — then the reminder is the plain text it always was.
 *
 * The word: only a card the learner already answered (`state <> new`) of an active word. First the due cards by
 * {@see LearnerStatsService::dueQuery()} (the same rule as the number in the reminder; hard cards first, then the
 * oldest due — the order of the card picker), and when nothing is due the cards nearest to their date. At most
 * `quiz.candidates` of them are tried, one query for the lot. The exercise is `meaning`, or `reverse` when `meaning` is not
 * available to the learner (`ExerciseSelector::available`: config flag, plan, `learners.meta.exercises`); neither — no question.
 *
 * The options come from {@see ExercisePromptBuilder} with a seed of the question's token: the same distractor rules as
 * in the app (a word sharing a sense with the right answer is never an option), and a card it could not give four
 * options for (it falls back to flash) is skipped. A candidate whose options would read the same on the cut buttons
 * is skipped too.
 *
 * Never throws: whatever goes wrong while choosing is reported and answered with `null` — the reminder must go out.
 */
final class ReminderQuizBuilder
{
    public function __construct(
        private readonly LearnerStatsService $stats,
        private readonly WordTranslationResolver $translations,
        private readonly ExercisePromptBuilder $prompts,
        private readonly ExerciseSelector $selector,
        private readonly EntitlementResolver $entitlements,
    ) {}

    /** Whether this reminder may be a question at all (the table of docs/12 §7.5): not before the learner has a word of their own. */
    public function eligible(ReminderContent $content): bool
    {
        if (! (bool) config('learning.reminders.quiz.enabled', true)) {
            return false;
        }

        return match ($content->kind) {
            ReminderKind::Review, ReminderKind::StreakAtRisk, ReminderKind::NewWords => true,
            // The rare reminders of the sparse days only ask the learner not to forget the bot: no exam there.
            ReminderKind::Inactive => $content->idleDays <= $this->maxIdleDays(),
            ReminderKind::NeverStarted, ReminderKind::ChooseCourse => false,
        };
    }

    public function tryBuild(Learner $learner, ReminderContent $content, string $locale, ?CarbonImmutable $now = null): ?ReminderQuiz
    {
        if (! $this->eligible($content)) {
            return null;
        }

        try {
            return $this->build($learner, $locale, $now ?? CarbonImmutable::now());
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function build(Learner $learner, string $locale, CarbonImmutable $now): ?ReminderQuiz
    {
        // The exercise the learner really has: the same check as the training (config flag of the exercise, the plan, their settings).
        $settings = LearnerExerciseSettings::fromLearner($learner);
        $entitlement = $this->entitlements->for($learner, $now);
        $type = null;
        foreach ([ExerciseType::Meaning, ExerciseType::Reverse] as $candidate) {
            if ($this->selector->available($candidate, $entitlement, $settings)) {
                $type = $candidate;
                break;
            }
        }
        if ($type === null) {
            return null;
        }

        $candidates = $this->candidates($learner, $now);
        if ($candidates->isEmpty()) {
            return null;
        }

        $words = [];
        foreach ($candidates as $row) {
            if ($row->word !== null) {
                $words[] = $row->word;
            }
        }
        $translations = $this->translations->resolveMany($words, $locale);

        foreach ($words as $word) {
            $translation = $translations[(int) $word->id] ?? null;
            if ($translation === null || trim($translation->text) === '' || trim($word->word) === '') {
                continue;
            }

            $token = Str::random(10);
            $prompt = $this->prompts->build($type, $word, $translation, $locale, null, crc32($token));
            // Fewer than three distractors: the builder gave a flash card — no choice to put in a chat.
            if ($prompt->type !== $type || $prompt->options === null) {
                continue;
            }

            $options = $prompt->options;
            $correct = array_search($this->prompts->correctOption($type, $word, $translation), $options, true);
            if (! is_int($correct) || ! $this->distinctOnButtons($options)) {
                continue;
            }

            return new ReminderQuiz(
                wordId: (int) $word->id,
                word: trim($word->word),
                exercise: $type,
                options: $options,
                correctIndex: $correct,
                answer: trim($translation->text),
                token: $token,
            );
        }

        return null;
    }

    /**
     * @return Collection<int, WordRepetition> with `word` loaded
     */
    private function candidates(Learner $learner, CarbonImmutable $now): Collection
    {
        $limit = max(1, (int) config('learning.reminders.quiz.candidates', 3));

        $due = $this->stats->dueQuery($learner, $now)
            ->with('word')
            ->orderByDesc('is_hard')
            ->orderBy('next_review_at')
            ->limit($limit)
            ->get();
        if ($due->isNotEmpty()) {
            return $due;
        }

        // Nothing is due (a streak to keep, a break without due words): the card whose date is nearest.
        return WordRepetition::query()
            ->where('learner_id', $learner->id)
            ->whereIn('state', [RepetitionState::Review->value, RepetitionState::Learning->value, RepetitionState::Relearning->value])
            ->whereHas('word', fn (Builder $word) => $word->where('is_active', true))
            ->with('word')
            ->orderBy('next_review_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  list<string>  $options
     */
    private function distinctOnButtons(array $options): bool
    {
        $labels = array_map(static fn (string $option): string => mb_strtolower(ReminderQuiz::buttonLabel($option)), $options);

        return count(array_unique($labels)) === count($labels);
    }

    private function maxIdleDays(): int
    {
        return max(0, (int) config('learning.reminders.quiz.max_idle_days', 7));
    }
}
