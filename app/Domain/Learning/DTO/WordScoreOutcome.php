<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Learning\Contracts\AnswerScoreRecorder;
use App\Domain\Learning\Enums\WordScoreReason;

/**
 * What the word points made of one answer ({@see AnswerScoreRecorder::record()}, docs/32 §3.1):
 *
 *   kind / base        — the credited kind and its points by the rules (null / 0 when nothing was credited);
 *   weekAdded / monthAdded — what each count really got (the slots and the day cap applied);
 *   week / month       — the counts of the rating day AFTER this answer; null when the answer wrote no credit (the day row is not read
 *                        then: the client keeps what it showed);
 *   visible            — the answer may be shown to the learner (`live` and a count of the day on v2); `off` / `shadow` - never;
 *   firstCreditOfWeek  — this answer fixed the learner's week (`learner_score_periods`): the moment of the weekly placement of v2 (S8);
 *   newLongLexeme      — this answer gave the lexeme its first long repetition (`learner_lexemes.first_long_on`): the achievement
 *                        `long_recall_25` (S14) is looked at after the commit.
 */
final readonly class WordScoreOutcome
{
    public function __construct(
        public WordScoreReason $reason,
        public ?ScoreKind $kind = null,
        public int $base = 0,
        public int $weekAdded = 0,
        public int $monthAdded = 0,
        public ?ScoreDayCount $week = null,
        public ?ScoreDayCount $month = null,
        public bool $visible = false,
        public bool $firstCreditOfWeek = false,
        public bool $newLongLexeme = false,
    ) {}

    /** An answer that wrote no credit. */
    public static function skipped(WordScoreReason $reason, bool $visible = false): self
    {
        return new self($reason, visible: $visible);
    }

    public function credited(): bool
    {
        return $this->kind !== null;
    }

    /**
     * The `score` block of the answer of the training and of the game (docs/32 §8.1); null when it must not be shown (`off`, `shadow`,
     * `live` before the v2 start). `today` is the weekly count; `today_month` - only when the month differs from it (another category or
     * another sum). Both null when the answer wrote no credit.
     *
     * @return array{kind: string|null, reason: string, base: int, week: int, month: int, today: array<string, mixed>|null, today_month: array<string, mixed>|null}|null
     */
    public function toApi(): ?array
    {
        if (! $this->visible) {
            return null;
        }

        $week = $this->week?->toArray();
        $month = $this->month?->toArray();

        return [
            'kind' => $this->kind?->value,
            'reason' => $this->reason->value,
            'base' => $this->base,
            'week' => $this->weekAdded,
            'month' => $this->monthAdded,
            'today' => $week,
            'today_month' => $month !== null && ($week === null || $month['category'] !== $week['category'] || $month['points'] !== $week['points'] || $month['cap'] !== $week['cap']) ? $month : null,
        ];
    }
}
