<?php

declare(strict_types=1);

namespace App\Domain\Practice\Enums;

/**
 * The verdict on an answer (`practice_attempts.verdict`, `battle_answers.verdict`, docs/16 §2.6, §2.7).
 * `timeout` is written only by the server of a battle when the deadline passes without an answer.
 */
enum Verdict: string
{
    case Correct = 'correct';
    /** A dictation with one safe typo: shown as "there is a typo", never counted as correct on the first try. */
    case Typo = 'typo';
    case Wrong = 'wrong';
    /** "I don't know" / an empty answer: a hint plus a mistake. */
    case Skipped = 'skipped';
    case Timeout = 'timeout';

    /** The answer goes to the list of mistakes of the summary (docs/16 §6.4). */
    public function isMistake(): bool
    {
        return $this !== self::Correct;
    }
}
