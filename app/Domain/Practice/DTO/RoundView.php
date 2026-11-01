<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use Carbon\CarbonImmutable;

/**
 * One round of a battle for a viewer (docs/16 §7.4, `BattleRoundResource`): the element as the mode's `project()` shows it
 * in the viewer's language (the resource cleans it once more of the keys of the snapshot), and - once the round is resolved
 * (both answers are final, or the deadline and its grace have passed; §8.5) - the review:
 *
 *   reveal = { correct: {...the right answer in the form of the mode: correct_text | correct_option_ids | expected | pairs},
 *              me: {answer, verdict, points}, opponent: {answer, verdict, points}, explanation, translation }
 *
 * `answer` is the payload as it was accepted (null for a time-out); `null` reveal before the round is resolved - the
 * opponent's answer and the right one are never on the device earlier. `phase` is the phase of THIS round: `reveal` for a round
 * that is over, the battle's phase for the current one (null in the countdown); `deadlineAt` / `phaseEndsAt` only for the
 * current round.
 */
final readonly class RoundView
{
    /**
     * @param  array<string, mixed>  $element
     * @param  array{correct: array<string, mixed>, me: array{answer: array<string, mixed>|null, verdict: string, points: int}, opponent: array{answer: array<string, mixed>|null, verdict: string, points: int}, explanation: string|null, translation: string|null}|null  $reveal
     */
    public function __construct(
        public int $no,
        public ?string $phase,
        public ?CarbonImmutable $deadlineAt,
        public ?CarbonImmutable $phaseEndsAt,
        public CarbonImmutable $serverNow,
        public array $element,
        public ?array $reveal,
    ) {}
}
