<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Learning\DTO\ReviewOutcome;
use App\Domain\Learning\DTO\TrainingStep;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Services\GapFillBuilder;
use App\Domain\Learning\Services\TrainingFlowService;
use App\Models\Learner;
use App\Models\TrainingSession;
use App\Models\Word;

/**
 * Answers a served card the way the Mini App does, whatever exercise it came with (docs/06 §12.2, §13.1): a flash card
 * takes the learner's `quality`, a four-option card the picked option as `answer`, a typed card the word. The early
 * ladder serves objective exercises from the first repeat on, so a test that walks a session cannot send `quality`
 * blindly any more; `$quality >= 3` means "answer right", below it "answer wrong".
 */
final class CardAnswers
{
    /**
     * The body of `POST /review` for a card of `/start`, `/next` or `/review`.
     *
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    public static function body(array $card, int $quality = 4, int $timeSpentMs = 1000): array
    {
        $type = self::typeOf($card);
        $body = [
            'word_id' => (int) $card['word_id'],
            'time_spent_ms' => $timeSpentMs,
            'serve_token' => $card['serve_token'] ?? null,
            'exercise' => $type->value,
        ];

        if ($type->selfGraded()) {
            return $body + ['quality' => $quality];
        }

        return $body + ['answer' => $quality >= 3 ? self::correctAnswer($card) : self::wrongAnswer($card)];
    }

    /** @param  array<string, mixed>  $card */
    public static function typeOf(array $card): ExerciseType
    {
        $type = $card['prompt']['type'] ?? 'flash';

        return ExerciseType::from(is_string($type) ? $type : 'flash');
    }

    /**
     * The right answer of a card that is not self-graded: the English word for reverse / spelling, the form in the
     * example for gap-fill, the translation (session language `ru`) for meaning / audio / pretest.
     *
     * @param  array<string, mixed>  $card
     */
    public static function correctAnswer(array $card): string
    {
        $word = Word::query()->findOrFail((int) $card['word_id']);
        $type = self::typeOf($card);

        return match ($type) {
            ExerciseType::Reverse, ExerciseType::Spelling => trim($word->word),
            ExerciseType::GapFill => app(GapFillBuilder::class)->fromText($word->word, is_string($card['example'] ?? null) ? $card['example'] : $word->example)?->expected ?? trim($word->word),
            default => trim(app(WordTranslationResolver::class)->resolve($word, 'ru')->text),
        };
    }

    /**
     * A wrong answer: an option other than the right one on a four-option card, a nonsense word on a typed one.
     *
     * @param  array<string, mixed>  $card
     */
    public static function wrongAnswer(array $card): string
    {
        $correct = mb_strtolower(trim(self::correctAnswer($card)));
        $options = $card['prompt']['options'] ?? null;
        if (is_array($options)) {
            foreach ($options as $option) {
                if (is_string($option) && mb_strtolower(trim($option)) !== $correct) {
                    return $option;
                }
            }
        }

        return 'zzzz-not-it';
    }

    /** The same at the service level: grades the step's card right (`$quality >= 3`) or wrong, as the client would. */
    public static function grade(TrainingFlowService $flow, TrainingSession $session, Learner $learner, TrainingStep $step, int $quality = 4, int $timeSpentMs = 500): ReviewOutcome
    {
        $card = self::cardOf($step);

        if (self::typeOf($card)->selfGraded()) {
            return $flow->review($session, $learner, (int) $card['word_id'], $quality, $timeSpentMs, $step->serveToken);
        }

        $answer = $quality >= 3 ? self::correctAnswer($card) : self::wrongAnswer($card);

        return $flow->answer($session, $learner, (int) $card['word_id'], $answer, $timeSpentMs, $step->serveToken);
    }

    /**
     * The card array of a step, as the resource would send it (what the helpers above read).
     *
     * @return array<string, mixed>
     */
    public static function cardOf(TrainingStep $step): array
    {
        return [
            'word_id' => (int) $step->word?->id,
            'serve_token' => $step->serveToken,
            'example' => $step->example,
            'prompt' => $step->prompt === null ? null : ['type' => $step->prompt->type->value, 'options' => $step->prompt->options],
        ];
    }
}
