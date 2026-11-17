<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Services\ExercisePromptBuilder;

/**
 * The question of a reminder (docs/12 §7.5): one of the learner's own words with four options, built by
 * {@see ExercisePromptBuilder} (so the options never hold a second right answer).
 * `answer` is the translation in the learner's language for both kinds — the text of the verdict ("dog — собака"); for
 * `reverse` it is also the question. `correctIndex` points into `options`, which are in the builder's stable order for
 * `token` (the seed). Never leaves the server: the buttons carry the token and the tapped index only.
 */
final readonly class ReminderQuiz
{
    /** Width of a button label (the Bot API shows ~40 characters of a button in a narrow chat); the verdict says the full text. */
    public const BUTTON_WIDTH = 40;

    /** @param list<string> $options */
    public function __construct(
        public int $wordId,
        public string $word,
        public ExerciseType $exercise,
        public array $options,
        public int $correctIndex,
        public string $answer,
        public string $token,
    ) {}

    public static function buttonLabel(string $option): string
    {
        return mb_strimwidth($option, 0, self::BUTTON_WIDTH, '…');
    }
}
