<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

/**
 * The numbers of the score (docs/18 §12.0, `config/game.php` `scoring.*`): a correct answer, a typo, from which answer in a row the
 * combo pays and how much, and the most it pays. Read from the config once, so `RunScoring` stays a pure function.
 */
final readonly class ScoringRules
{
    public function __construct(
        public int $correct = 10,
        public int $typo = 5,
        public int $comboFrom = 3,
        public int $comboBonus = 5,
        public int $comboBonusMax = 15,
    ) {}

    public static function fromConfig(): self
    {
        $scoring = (array) config('game.scoring', []);

        return new self(
            correct: (int) ($scoring['correct'] ?? 10),
            typo: (int) ($scoring['typo'] ?? 5),
            comboFrom: (int) ($scoring['combo_from'] ?? 3),
            comboBonus: (int) ($scoring['combo_bonus'] ?? 5),
            comboBonusMax: (int) ($scoring['combo_bonus_max'] ?? 15),
        );
    }
}
